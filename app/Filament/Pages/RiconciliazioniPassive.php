<?php

namespace App\Filament\Pages;

use App\Services\Reporting\RiconciliazioniPassiveBuilder;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Quadratura per fattura passiva: elenco delle fatture ricevute con il movimento
 * bancario di pagamento (o il rimborso spese). Esportabile. Solo admin.
 */
class RiconciliazioniPassive extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|\UnitEnum|null $navigationGroup = 'Controllo Finanziario';

    protected static ?string $title = 'Riconciliazioni fatture passive';

    protected static ?string $navigationLabel = 'Riconc. fatture passive';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.riconciliazioni-fatture';

    public string $from;

    public string $until;

    public string $emptyMessage = 'Nessuna fattura passiva nel periodo selezionato.';

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
        return app(RiconciliazioniPassiveBuilder::class)->build(
            Carbon::parse($this->from)->startOfDay(),
            Carbon::parse($this->until)->endOfDay(),
        );
    }

    /**
     * @return array<int, int>
     */
    public function numericColumns(): array
    {
        return RiconciliazioniPassiveBuilder::NUMERIC_COLUMNS;
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
        return app(RiconciliazioniPassiveBuilder::class)->export(
            Carbon::parse($this->from)->startOfDay(),
            Carbon::parse($this->until)->endOfDay(),
            $format,
        );
    }
}
