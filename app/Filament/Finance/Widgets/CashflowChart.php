<?php

namespace App\Filament\Finance\Widgets;

use App\Filament\Finance\Concerns\HasFinancePeriod;
use App\Models\BankTransaction;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Carbon;

/**
 * Cashflow mensile: entrate vs uscite dai movimenti bancari del periodo, più
 * il saldo netto cumulato mese per mese.
 */
class CashflowChart extends ChartWidget
{
    use HasFinancePeriod;
    use InteractsWithPageFilters;

    protected ?string $heading = 'Cashflow (entrate / uscite)';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()->isAdmin();
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $from = $this->periodFrom()->startOfMonth();
        $until = $this->periodUntil()->endOfMonth();

        // Prepara i bucket mensili del periodo.
        $labels = [];
        $entrate = [];
        $uscite = [];
        $keys = [];
        for ($cursor = $from->copy(); $cursor <= $until; $cursor->addMonth()) {
            $key = $cursor->format('Y-m');
            $keys[$key] = count($labels);
            $labels[] = $cursor->locale('it')->isoFormat('MMM YY');
            $entrate[] = 0.0;
            $uscite[] = 0.0;
        }

        BankTransaction::whereBetween('booked_at', [$from, $until])
            ->get(['booked_at', 'amount'])
            ->each(function (BankTransaction $t) use (&$entrate, &$uscite, $keys): void {
                $key = Carbon::parse($t->booked_at)->format('Y-m');
                if (! isset($keys[$key])) {
                    return;
                }
                $i = $keys[$key];
                if ((float) $t->amount >= 0) {
                    $entrate[$i] += (float) $t->amount;
                } else {
                    $uscite[$i] += abs((float) $t->amount);
                }
            });

        // Saldo netto cumulato nel periodo.
        $cumulato = [];
        $running = 0.0;
        foreach ($entrate as $i => $in) {
            $running += $in - $uscite[$i];
            $cumulato[] = round($running, 2);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Entrate',
                    'data' => array_map(fn ($v): float => round($v, 2), $entrate),
                    'backgroundColor' => 'rgba(34, 197, 94, 0.5)',
                    'borderColor' => 'rgb(34, 197, 94)',
                ],
                [
                    'label' => 'Uscite',
                    'data' => array_map(fn ($v): float => round($v, 2), $uscite),
                    'backgroundColor' => 'rgba(239, 68, 68, 0.5)',
                    'borderColor' => 'rgb(239, 68, 68)',
                ],
                [
                    'label' => 'Saldo cumulato',
                    'data' => $cumulato,
                    'type' => 'line',
                    'borderColor' => 'rgb(59, 130, 246)',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'fill' => false,
                ],
            ],
            'labels' => $labels,
        ];
    }
}
