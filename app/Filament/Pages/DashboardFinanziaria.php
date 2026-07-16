<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Costi\CostoResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\PassiveInvoices\PassiveInvoiceResource;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Costo;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Services\Reporting\FinancialOverviewBuilder;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Dashboard finanziaria mensile, con le due entità tenute separate: G8LABS (SRL,
 * regime ordinario) e Giorgio Giotto (P.IVA forfettaria). Solo admin.
 */
class DashboardFinanziaria extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Controllo Finanziario';

    protected static ?string $title = 'Dashboard finanziaria';

    protected static ?string $navigationLabel = 'Dashboard finanziaria';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.dashboard-finanziaria';

    private const MESI = [
        1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile', 5 => 'Maggio', 6 => 'Giugno',
        7 => 'Luglio', 8 => 'Agosto', 9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre',
    ];

    public int $year;

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->isAdmin();
    }

    /**
     * Modale con l'elenco dei movimenti bancari dietro una cella della tabella
     * mensile (entrate/uscite di un mese, o uscite non riconciliate). I criteri
     * ricalcano esattamente quelli del builder: giroconti sempre esclusi.
     */
    public function movimentiAction(): Action
    {
        return Action::make('movimenti')
            ->modalHeading(fn (array $arguments): string => $this->movimentiTitolo($arguments))
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Chiudi')
            ->modalContent(function (array $arguments) {
                $tipo = (string) ($arguments['tipo'] ?? 'entrate');
                $mese = (int) ($arguments['mese'] ?? 0);
                $anno = (int) ($arguments['anno'] ?? $this->year);

                return view('filament.pages.partials.movimenti-modal', [
                    'movimenti' => $this->movimenti($tipo, $mese, $anno),
                    'tipo' => $tipo,
                ]);
            });
    }

    private function movimentiTitolo(array $arguments): string
    {
        $tipo = (string) ($arguments['tipo'] ?? 'entrate');
        $mese = (int) ($arguments['mese'] ?? 0);
        $anno = (int) ($arguments['anno'] ?? $this->year);

        $etichetta = match ($tipo) {
            'uscite' => 'Uscite',
            'non_attribuite' => 'Uscite non riconciliate',
            default => 'Entrate',
        };
        $periodo = isset(self::MESI[$mese]) ? self::MESI[$mese].' '.$anno : (string) $anno;

        return "{$etichetta} — {$periodo}";
    }

    /**
     * Movimenti bancari corrispondenti alla cella cliccata. `mese` a 0 = tutto
     * l'anno (usato dal totale e dall'avviso uscite non riconciliate).
     *
     * @return Collection<int, BankTransaction>
     */
    public function movimenti(string $tipo, int $mese, int $anno): Collection
    {
        $query = BankTransaction::query()
            ->with('bankAccount')
            ->withCount('reconciliations')
            ->whereYear('booked_at', $anno)
            // I giroconti tra conti propri sono esclusi dal quadro operativo,
            // esattamente come nel FinancialOverviewBuilder.
            ->whereNull('transfer_pair_id');

        if ($mese >= 1 && $mese <= 12) {
            $query->whereMonth('booked_at', $mese);
        }

        match ($tipo) {
            'uscite' => $query->where('amount', '<', 0),
            'non_attribuite' => $query->where('amount', '<', 0)->doesntHave('reconciliations'),
            default => $query->where('amount', '>=', 0),
        };

        // Ordinati per importo (i più grandi in cima), a prescindere dal segno.
        return $query->orderByRaw('ABS(amount) DESC')->get();
    }

    /**
     * Modale con l'elenco dei documenti dietro una cella "Ricavi"/"Costi" della
     * tabella mensile G8LABS. I criteri ricalcano esattamente quelli del
     * FinancialOverviewBuilder, così il totale in fondo alla modale coincide con
     * la cella cliccata.
     */
    public function documentiAction(): Action
    {
        return Action::make('documenti')
            ->modalHeading(fn (array $arguments): string => $this->documentiTitolo($arguments))
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Chiudi')
            ->modalContent(function (array $arguments) {
                $tipo = (string) ($arguments['tipo'] ?? 'ricavi');
                $mese = (int) ($arguments['mese'] ?? 0);
                $anno = (int) ($arguments['anno'] ?? $this->year);

                return view('filament.pages.partials.documenti-modal', [
                    'documenti' => $this->documenti($tipo, $mese, $anno),
                    'tipo' => $tipo,
                ]);
            });
    }

    private function documentiTitolo(array $arguments): string
    {
        $tipo = (string) ($arguments['tipo'] ?? 'ricavi');
        $mese = (int) ($arguments['mese'] ?? 0);
        $anno = (int) ($arguments['anno'] ?? $this->year);

        $etichetta = $tipo === 'costi' ? 'Costi documentati' : 'Ricavi';
        $periodo = isset(self::MESI[$mese]) ? self::MESI[$mese].' '.$anno : (string) $anno;

        return "{$etichetta} — {$periodo}";
    }

    /**
     * Documenti (fatture attive per i ricavi; fatture passive, costi e spese per
     * i costi) dietro una cella della tabella mensile. Le note di credito entrano
     * col segno negativo, come nel builder. `mese` a 0 = tutto l'anno.
     *
     * @return Collection<int, array{data: ?Carbon, numero: string, controparte: string, tipo: string, importo: float, url: ?string}>
     */
    public function documenti(string $tipo, int $mese, int $anno): Collection
    {
        $mese = ($mese >= 1 && $mese <= 12) ? $mese : null;

        $documenti = $tipo === 'costi'
            ? $this->documentiCosti($mese, $anno)
            : $this->documentiRicavi($mese, $anno);

        // Ordinati per importo (i più grandi in cima), a prescindere dal segno.
        return $documenti->sortByDesc(fn (array $r) => abs((float) $r['importo']))->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function documentiRicavi(?int $mese, int $anno): Collection
    {
        $query = Invoice::query()
            ->whereHas('client', fn ($q) => $q->where('invoicing_provider', Client::PROVIDER_FIC))
            ->where('status', '!=', 'draft')
            ->whereYear('issue_date', $anno)
            ->with(['client', 'items', 'hours', 'expenses'])
            ->orderBy('issue_date');

        if ($mese !== null) {
            $query->whereMonth('issue_date', $mese);
        }

        return $query->get()->map(function (Invoice $inv): array {
            $sign = $inv->isCreditNote() ? -1 : 1;

            return [
                'data' => $inv->issue_date ? Carbon::parse($inv->issue_date) : null,
                'numero' => (string) $inv->number,
                'controparte' => $inv->client->name ?? '—',
                'tipo' => $inv->isCreditNote() ? 'Nota di credito' : 'Fattura',
                'importo' => round($sign * $inv->taxableAmount(), 2),
                'url' => InvoiceResource::getUrl('view', ['record' => $inv]),
            ];
        });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function documentiCosti(?int $mese, int $anno): Collection
    {
        $rows = collect();

        $passives = PassiveInvoice::query()
            ->with('supplier')
            ->whereYear('document_date', $anno)
            ->when($mese !== null, fn ($q) => $q->whereMonth('document_date', $mese))
            ->orderBy('document_date')
            ->get();
        foreach ($passives as $p) {
            $sign = $p->isCreditNote() ? -1 : 1;
            $rows->push([
                'data' => $p->document_date,
                'numero' => (string) $p->number,
                'controparte' => $p->supplier->name ?? '—',
                'tipo' => $p->isCreditNote() ? 'Nota di credito passiva' : 'Fattura passiva',
                'importo' => round($sign * (float) $p->amount_net, 2),
                'url' => PassiveInvoiceResource::getUrl('view', ['record' => $p]),
            ]);
        }

        // La liquidazione IVA (F24) è imposta di giro, non costo operativo: esclusa
        // dai costi come nel builder.
        $costi = Costo::query()
            ->where('category', '!=', Costo::CATEGORY_VAT)
            ->whereYear('date', $anno)
            ->when($mese !== null, fn ($q) => $q->whereMonth('date', $mese))
            ->orderBy('date')
            ->get();
        foreach ($costi as $c) {
            $rows->push([
                'data' => $c->date,
                'numero' => (string) ($c->description ?: 'Costo'),
                'controparte' => $c->category ?? '—',
                'tipo' => 'Costo',
                'importo' => round((float) $c->amount - (float) $c->vat_amount, 2),
                'url' => CostoResource::getUrl('edit', ['record' => $c]),
            ]);
        }

        $expenses = Expense::query()
            ->whereNull('passive_invoice_id')
            ->whereYear('date', $anno)
            ->when($mese !== null, fn ($q) => $q->whereMonth('date', $mese))
            ->orderBy('date')
            ->get();
        foreach ($expenses as $e) {
            $rows->push([
                'data' => $e->date,
                'numero' => (string) ($e->notes ?: 'Spesa'),
                'controparte' => $e->conto ?: '—',
                'tipo' => 'Spesa',
                'importo' => round((float) $e->amount, 2),
                'url' => ExpenseResource::getUrl('view', ['record' => $e]),
            ]);
        }

        return $rows;
    }

    public function mount(): void
    {
        $this->year = (int) now()->year;
    }

    /**
     * @return array<int, int>
     */
    public function anniDisponibili(): array
    {
        $current = (int) now()->year;

        return range($current, $current - 4);
    }

    /**
     * @return array<string, mixed>
     */
    public function dati(): array
    {
        $builder = app(FinancialOverviewBuilder::class);

        return [
            'g8labs' => $builder->g8labsMonthly($this->year),
            'g8labsTotali' => $builder->g8labsTotals($this->year),
            'snapshot' => $builder->g8labsSnapshot(),
            'forfettario' => $builder->forfettario($this->year),
        ];
    }
}
