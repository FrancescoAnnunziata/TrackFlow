<?php

namespace App\Filament\Pages;

use App\Services\Reporting\RiconciliazioniAttiveBuilder;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Quadratura per fattura attiva: elenco delle fatture emesse con il movimento
 * bancario di incasso e le note di credito collegate. Esportabile. Solo admin.
 */
class RiconciliazioniAttive extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|\UnitEnum|null $navigationGroup = 'Controllo Finanziario';

    protected static ?string $title = 'Riconciliazioni fatture attive';

    protected static ?string $navigationLabel = 'Riconc. fatture attive';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.riconciliazioni-fatture';

    public string $from;

    public string $until;

    public string $emptyMessage = 'Nessuna fattura attiva nel periodo selezionato.';

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->isAdmin();
    }

    public function mount(): void
    {
        $this->from = now()->startOfYear()->toDateString();
        $this->until = now()->endOfYear()->toDateString();
    }

    /**
     * @return array{headings: array<int, string>, rows: array<int, array{kind: string, cells: array<int, mixed>}>}
     */
    public function tableData(): array
    {
        return app(RiconciliazioniAttiveBuilder::class)->build(
            Carbon::parse($this->from)->startOfDay(),
            Carbon::parse($this->until)->endOfDay(),
        );
    }

    /**
     * @return array<int, int>
     */
    public function numericColumns(): array
    {
        return RiconciliazioniAttiveBuilder::NUMERIC_COLUMNS;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportXlsx')
                ->label('Esporta Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn (): BinaryFileResponse => $this->export('xlsx')),
            Action::make('exportCsv')
                ->label('Esporta CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): BinaryFileResponse => $this->export('csv')),
        ];
    }

    public function export(string $format): BinaryFileResponse
    {
        return app(RiconciliazioniAttiveBuilder::class)->export(
            Carbon::parse($this->from)->startOfDay(),
            Carbon::parse($this->until)->endOfDay(),
            $format,
        );
    }
}
