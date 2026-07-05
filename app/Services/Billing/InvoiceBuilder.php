<?php

namespace App\Services\Billing;

use App\Models\Client;
use App\Models\Expense;
use App\Models\Hour;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Genera una fattura bozza dal cliente e da un periodo, guidato SOLO dalla
 * configurazione del cliente (nessuna regola hardcoded per singolo cliente).
 *
 * Copre: forfait; a ore (tariffa unica o per-utente); minimo garantito;
 * anticipato con conguaglio del periodo precedente (caso Alsea).
 */
class InvoiceBuilder
{
    /**
     * @param  CarbonInterface  $periodStart  Primo giorno del periodo consulenziale da fatturare.
     */
    public function build(Client $client, CarbonInterface $periodStart): Invoice
    {
        $months = max(1, (int) $client->billing_period_months);
        $start = CarbonImmutable::instance($periodStart)->startOfMonth();
        [$from, $to] = $this->window($start, $months);

        return DB::transaction(function () use ($client, $start, $months, $from, $to): Invoice {
            $invoice = Invoice::create([
                'user_id' => auth()->id(),
                'client_id' => $client->id,
                // Nessun numero inventato: lo assegna FIC all'invio.
                'issue_date' => now(),
                'period_from' => $from,
                'period_to' => $to,
                'vat_rate' => $client->vat_rate ?? 22,
                'status' => 'draft',
            ]);

            $lines = collect();
            $expenseFrom = $from;
            $expenseTo = $to;

            if ($client->billing_model === Client::MODEL_FORFAIT) {
                $lines = $lines->merge($this->forfaitLines($client, $months));
            } elseif ($client->billing_model === Client::MODEL_DAILY) {
                $lines = $lines->merge($this->dailyLines($client, $from, $to, $invoice));
            } elseif ($client->billing_timing === Client::TIMING_ADVANCE) {
                $lines = $lines->merge($this->advanceLines($client, $months));

                if ($client->reconcile_previous_period) {
                    [$pFrom, $pTo] = $this->window($start->subMonths($months), $months);
                    $lines = $lines->merge($this->reconciliationLines($client, $pFrom, $pTo, $invoice));
                    // Le spese seguono il periodo conguagliato.
                    $expenseFrom = $pFrom;
                    $expenseTo = $pTo;
                }
            } else { // hourly, posticipato
                $lines = $lines->merge($this->hourlyLines($client, $from, $to, $months, $invoice));
            }

            // Extra fisso ricorrente e spese (art. 15) valgono per tutti i modelli.
            $lines = $lines->merge($this->extraLine($client, $months));
            $lines = $lines->merge($this->expenseLine($client, $expenseFrom, $expenseTo, $invoice));

            $lines->values()->each(function (array $line, int $i) use ($invoice): void {
                $invoice->items()->create($line + ['sort' => $i]);
            });

            return $invoice->fresh(['items', 'hours', 'expenses', 'client']);
        });
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(CarbonImmutable $start, int $months): array
    {
        return [$start->startOfMonth(), $start->addMonths($months - 1)->endOfMonth()];
    }

    private function periodLabel(Client $client, ?string $suffix = null): string
    {
        $base = trim((string) ($client->consulting_label ?? '')) ?: 'Consulenza';

        return $suffix ? $base.' — '.$suffix : $base;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function forfaitLines(Client $client, int $months): array
    {
        // Righe di dettaglio se configurate (importi mensili × mesi del periodo).
        $lines = collect($client->forfait_lines ?? [])
            ->filter(fn ($line): bool => filled($line['name'] ?? null) && filled($line['amount'] ?? null));

        if ($lines->isNotEmpty()) {
            return $lines->map(fn (array $line): array => [
                'name' => (string) $line['name'],
                'qty' => 1,
                'measure' => '',
                'net_price' => round((float) $line['amount'] * $months, 2),
                'vat_kind' => InvoiceItem::VAT_STANDARD,
                'line_kind' => 'consulting',
            ])->values()->all();
        }

        return [[
            'name' => $this->periodLabel($client),
            'qty' => 1,
            'measure' => '',
            'net_price' => round((float) ($client->forfait_amount ?? 0) * $months, 2),
            'vat_kind' => InvoiceItem::VAT_STANDARD,
            'line_kind' => 'consulting',
        ]];
    }

    /**
     * Riga a giornata: giornate = ceil(ore lavorate / ore per giornata), alla
     * tariffa giornaliera. Aggancia le ore coperte.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dailyLines(Client $client, CarbonImmutable $from, CarbonImmutable $to, Invoice $invoice): array
    {
        $hours = $this->billableHours($client, $from, $to);
        $invoice->hours()->syncWithoutDetaching($hours->pluck('id')->all());

        $totalHours = (float) $hours->sum('hours');
        $perDay = (float) ($client->hours_per_day ?: 8);

        if ($totalHours <= 0 || $perDay <= 0) {
            return [];
        }

        $days = (int) ceil($totalHours / $perDay);

        return [[
            'name' => $this->periodLabel($client),
            'qty' => $days,
            'measure' => 'gg',
            'net_price' => (float) ($client->daily_rate ?? 0),
            'vat_kind' => InvoiceItem::VAT_STANDARD,
            'line_kind' => 'consulting',
        ]];
    }

    /**
     * Righe a ore (posticipato): una riga per utente con la sua tariffa, più
     * eventuale conguaglio del minimo garantito. Aggancia le ore coperte.
     *
     * @return array<int, array<string, mixed>>
     */
    private function hourlyLines(Client $client, CarbonImmutable $from, CarbonImmutable $to, int $months, Invoice $invoice): array
    {
        $hours = $this->billableHours($client, $from, $to);
        $invoice->hours()->syncWithoutDetaching($hours->pluck('id')->all());

        $lines = [];
        $totalHours = 0.0;

        foreach ($this->hoursByUser($hours) as $group) {
            $qty = round($group['hours'], 2);
            $totalHours += $qty;
            $rate = (float) ($client->rateForUser($group['user_id']) ?? 0);

            $lines[] = [
                'name' => $this->periodLabel($client, $group['user_name']),
                'qty' => $qty,
                'measure' => 'h',
                'net_price' => $rate,
                'vat_kind' => InvoiceItem::VAT_STANDARD,
                'line_kind' => 'consulting',
            ];
        }

        // Minimo garantito: se le ore lavorate sono sotto il minimo, aggiungi la
        // differenza alla tariffa di default.
        $guaranteed = (float) ($client->minimum_hours_per_month ?? 0) * $months;
        if ($guaranteed > 0 && $totalHours < $guaranteed) {
            $lines[] = [
                'name' => $this->periodLabel($client, 'minimo garantito'),
                'qty' => round($guaranteed - $totalHours, 2),
                'measure' => 'h',
                'net_price' => (float) ($client->default_hourly_rate ?? 0),
                'vat_kind' => InvoiceItem::VAT_STANDARD,
                'line_kind' => 'consulting',
            ];
        }

        return $lines;
    }

    /**
     * Righe anticipo: minimo garantito del NUOVO periodo, alla tariffa di
     * default. Nessuna ora agganciata (non ancora lavorate).
     *
     * @return array<int, array<string, mixed>>
     */
    private function advanceLines(Client $client, int $months): array
    {
        $qty = round((float) ($client->minimum_hours_per_month ?? 0) * $months, 2);

        if ($qty <= 0) {
            return [];
        }

        return [[
            'name' => $this->periodLabel($client, 'minimo garantito anticipato'),
            'qty' => $qty,
            'measure' => 'h',
            'net_price' => (float) ($client->default_hourly_rate ?? 0),
            'vat_kind' => InvoiceItem::VAT_STANDARD,
            'line_kind' => 'advance',
        ]];
    }

    /**
     * Conguaglio del periodo precedente: ore oltre il minimo già fatturato,
     * alla tariffa di default. Aggancia le ore extra coperte.
     *
     * @return array<int, array<string, mixed>>
     */
    private function reconciliationLines(Client $client, CarbonImmutable $from, CarbonImmutable $to, Invoice $invoice): array
    {
        $months = max(1, (int) $client->billing_period_months);
        $hours = $this->billableHours($client, $from, $to);
        $worked = round((float) $hours->sum('hours'), 2);
        $guaranteed = (float) ($client->minimum_hours_per_month ?? 0) * $months;
        $overage = round($worked - $guaranteed, 2);

        if ($overage <= 0) {
            return [];
        }

        // Aggancia le ore del periodo precedente (tracciabilità).
        $invoice->hours()->syncWithoutDetaching($hours->pluck('id')->all());

        return [[
            'name' => $this->periodLabel($client, sprintf(
                'conguaglio ore extra %s-%s',
                $from->format('m/Y'),
                $to->format('m/Y'),
            )),
            'qty' => $overage,
            'measure' => 'h',
            'net_price' => (float) ($client->default_hourly_rate ?? 0),
            'vat_kind' => InvoiceItem::VAT_STANDARD,
            'line_kind' => 'reconciliation',
        ]];
    }

    /**
     * Extra fisso ricorrente (× mesi del periodo), se previsto.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extraLine(Client $client, int $months): array
    {
        $extra = round((float) ($client->monthly_extra_amount ?? 0) * $months, 2);

        if ($extra <= 0) {
            return [];
        }

        $name = trim((string) ($client->monthly_extra_label ?? '')) ?: $this->periodLabel($client, 'extra');

        return [[
            'name' => $name,
            'qty' => 1,
            'measure' => '',
            'net_price' => $extra,
            'vat_kind' => InvoiceItem::VAT_STANDARD,
            'line_kind' => 'extra',
        ]];
    }

    /**
     * Riga unica "Rimborsi spese" (art. 15) col totale delle spese del periodo;
     * aggancia le spese coperte (il dettaglio finisce nelle note del payload).
     *
     * @return array<int, array<string, mixed>>
     */
    private function expenseLine(Client $client, CarbonImmutable $from, CarbonImmutable $to, Invoice $invoice): array
    {
        $expenses = Expense::query()
            ->where('client_id', $client->id)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->whereDoesntHave('invoices')
            ->get();

        if ($expenses->isEmpty()) {
            return [];
        }

        $invoice->expenses()->syncWithoutDetaching($expenses->pluck('id')->all());

        return [[
            'name' => 'Rimborsi spese',
            'description' => 'Vedi note',
            'qty' => 1,
            'measure' => '',
            'net_price' => round((float) $expenses->sum('amount'), 2),
            'vat_kind' => InvoiceItem::VAT_ART15,
            'line_kind' => 'expenses',
        ]];
    }

    private function billableHours(Client $client, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Hour::query()
            ->where('billable', true)
            ->whereHas('clients', fn ($q) => $q->whereKey($client->id))
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->whereDoesntHave('invoices')
            ->with('user')
            ->get();
    }

    /**
     * @return array<int, array{user_id: int, user_name: string, hours: float}>
     */
    private function hoursByUser(Collection $hours): array
    {
        return $hours
            ->groupBy('user_id')
            ->map(fn (Collection $group) => [
                'user_id' => (int) $group->first()->user_id,
                'user_name' => $group->first()->user?->name ?? '—',
                'hours' => (float) $group->sum('hours'),
            ])
            ->values()
            ->all();
    }
}
