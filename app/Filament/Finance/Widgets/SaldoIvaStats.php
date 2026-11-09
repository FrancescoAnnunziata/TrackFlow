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
 * Saldo IVA del periodo: IVA a debito (fatture attive) contro IVA a credito
 * (fatture passive + costi con IVA detraibile). Saldo positivo = IVA da versare.
 */
class SaldoIvaStats extends StatsOverviewWidget
{
    use HasFinancePeriod;
    use InteractsWithPageFilters;

    protected ?string $heading = 'Saldo IVA';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()->canManageFinance();
    }

    protected function getStats(): array
    {
        $from = $this->periodFrom();
        $until = $this->periodUntil();

        $ivaDebito = Invoice::with('items')
            ->whereBetween('issue_date', [$from, $until])
            ->get()
            ->sum(fn (Invoice $i): float => $i->vatAmount());

        $ivaCredito = round(
            (float) PassiveInvoice::whereBetween('document_date', [$from, $until])->sum('amount_vat')
            + (float) Costo::whereBetween('date', [$from, $until])->sum('vat_amount'),
            2,
        );

        $saldo = round($ivaDebito - $ivaCredito, 2);

        return [
            Stat::make('IVA a debito', '€ '.number_format($ivaDebito, 2, ',', '.'))
                ->description('Sulle fatture attive')
                ->color('danger'),
            Stat::make('IVA a credito', '€ '.number_format($ivaCredito, 2, ',', '.'))
                ->description('Sulle passive e costi')
                ->color('success'),
            Stat::make($saldo >= 0 ? 'IVA da versare' : 'IVA a credito', '€ '.number_format(abs($saldo), 2, ',', '.'))
                ->color($saldo >= 0 ? 'warning' : 'success'),
        ];
    }
}
