<?php

namespace App\Filament\Pages;

use App\Filament\Finance\Widgets\CashflowChart;
use App\Filament\Finance\Widgets\ContoEconomicoStats;
use App\Filament\Finance\Widgets\MargineClienteTable;
use App\Filament\Finance\Widgets\SaldoIvaStats;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Dashboard di controllo finanziario: cashflow, conto economico, margine per
 * cliente e saldo IVA, filtrabili per periodo. Solo admin.
 */
class ControlloFinanziario extends Dashboard
{
    use HasFiltersForm;

    protected static string $routePath = '/controllo-finanziario';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Controllo Finanziario';

    protected static ?string $title = 'Controllo finanziario';

    protected static ?string $navigationLabel = 'Dashboard';

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->canManageFinance();
    }

    public static function getRoutePath(Panel $panel): string
    {
        return static::$routePath;
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->components([
                        DatePicker::make('from')
                            ->label('Dal')
                            ->default(now()->startOfYear()),
                        DatePicker::make('until')
                            ->label('Al')
                            ->default(now()->endOfYear()),
                    ]),
            ]);
    }

    public function getWidgets(): array
    {
        return [
            ContoEconomicoStats::class,
            SaldoIvaStats::class,
            CashflowChart::class,
            MargineClienteTable::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
