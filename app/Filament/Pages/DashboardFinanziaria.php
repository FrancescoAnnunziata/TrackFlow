<?php

namespace App\Filament\Pages;

use App\Services\Reporting\FinancialOverviewBuilder;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

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

    public int $year;

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->isAdmin();
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
