<?php

namespace App\Filament\Finance\Widgets;

use App\Filament\Finance\Concerns\HasFinancePeriod;
use App\Models\Costo;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Conto economico sintetico del periodo: ricavi (fatture attive) contro costi
 * (fatture passive + costi aziendali), con margine e marginalità %.
 */
class ContoEconomicoStats extends StatsOverviewWidget
{
    use HasFinancePeriod;
    use InteractsWithPageFilters;

    protected ?string $heading = 'Conto economico';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()->canManageFinance();
    }

    protected function getStats(): array
    {
        $from = $this->periodFrom();
        $until = $this->periodUntil();

        $ricavi = Invoice::with('items')
            ->whereBetween('issue_date', [$from, $until])
            ->get()
            ->sum(fn (Invoice $i): float => $i->taxableAmount());

        $costiPassive = (float) PassiveInvoice::whereBetween('document_date', [$from, $until])->sum('amount_net');
        $costiVari = (float) Costo::whereBetween('date', [$from, $until])->sum('amount');
        $costi = round($costiPassive + $costiVari, 2);

        $margine = round($ricavi - $costi, 2);
        $marginePct = $ricavi > 0 ? round($margine / $ricavi * 100, 1) : 0.0;

        return [
            Stat::make('Ricavi', '€ '.number_format($ricavi, 2, ',', '.'))
                ->description('Fatture attive del periodo')
                ->color('success'),
            Stat::make('Costi', '€ '.number_format($costi, 2, ',', '.'))
                ->description('Fatture passive + costi')
                ->color('danger'),
            Stat::make('Margine', '€ '.number_format($margine, 2, ',', '.'))
                ->description($marginePct.'% sui ricavi')
                ->color($margine >= 0 ? 'success' : 'danger'),
        ];
    }
}
