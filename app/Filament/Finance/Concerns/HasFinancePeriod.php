<?php

namespace App\Filament\Finance\Concerns;

use Illuminate\Support\Carbon;

/**
 * Legge il periodo selezionato nel filtro della dashboard finanziaria. In
 * assenza di filtro usa l'anno corrente. Da combinare con
 * Filament\Widgets\Concerns\InteractsWithPageFilters (espone $this->filters).
 */
trait HasFinancePeriod
{
    protected function periodFrom(): Carbon
    {
        $value = $this->filters['from'] ?? null;

        return $value ? Carbon::parse($value)->startOfDay() : now()->startOfYear();
    }

    protected function periodUntil(): Carbon
    {
        $value = $this->filters['until'] ?? null;

        return $value ? Carbon::parse($value)->endOfDay() : now()->endOfYear();
    }
}
