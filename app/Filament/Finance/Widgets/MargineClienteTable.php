<?php

namespace App\Filament\Finance\Widgets;

use App\Filament\Finance\Concerns\HasFinancePeriod;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Hour;
use App\Models\Invoice;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Marginalità per cliente nel periodo: fatturato (fatture attive) contro spese
 * sostenute per il cliente (rimborsi art. 15), con le ore lavorate come volume.
 */
class MargineClienteTable extends Widget
{
    use HasFinancePeriod;
    use InteractsWithPageFilters;

    protected string $view = 'filament.finance.margine-cliente';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()->isAdmin();
    }

    /**
     * @return Collection<int, array{name: string, fatturato: float, spese: float, ore: float, margine: float}>
     */
    public function getRows(): Collection
    {
        $from = $this->periodFrom();
        $until = $this->periodUntil();

        return Client::orderBy('name')->get()->map(function (Client $client) use ($from, $until): array {
            $fatturato = Invoice::with('items')
                ->where('client_id', $client->id)
                ->whereBetween('issue_date', [$from, $until])
                ->get()
                ->sum(fn (Invoice $i): float => $i->taxableAmount());

            $spese = (float) Expense::where('client_id', $client->id)
                ->whereBetween('date', [$from, $until])
                ->sum('amount');

            $ore = (float) Hour::whereBetween('date', [$from, $until])
                ->where('client_id', $client->id)
                ->sum('hours');

            return [
                'name' => $client->name,
                'fatturato' => round($fatturato, 2),
                'spese' => round($spese, 2),
                'ore' => round($ore, 2),
                'margine' => round($fatturato - $spese, 2),
            ];
        })
            ->filter(fn (array $r): bool => $r['fatturato'] != 0.0 || $r['spese'] != 0.0 || $r['ore'] != 0.0)
            ->sortByDesc('fatturato')
            ->values();
    }
}
