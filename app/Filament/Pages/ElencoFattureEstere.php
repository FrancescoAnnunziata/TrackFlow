<?php

namespace App\Filament\Pages;

use App\Services\Reporting\FattureEstereBuilder;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Elenco delle fatture passive estere di un periodo, con il link al PDF, da
 * mandare al commercialista per l'esterometro. Il periodo è libero: di norma
 * un mese, ma si può chiedere anche un recupero di più mesi. Solo admin.
 */
class ElencoFattureEstere extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|\UnitEnum|null $navigationGroup = 'Controllo Finanziario';

    protected static ?string $title = 'Elenco fatture estere';

    protected static ?string $navigationLabel = 'Elenco fatture estere';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.elenco-fatture-estere';

    public string $from;

    public string $until;

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->isAdmin();
    }

    public function mount(): void
    {
        $this->from = now()->startOfMonth()->toDateString();
        $this->until = now()->endOfMonth()->toDateString();
    }

    /**
     * @return array{headings: array<int, string>, rows: array<int, array<int, mixed>>, totale: float}
     */
    public function tableData(): array
    {
        return app(FattureEstereBuilder::class)->build(...$this->period());
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
        [$from, $until] = $this->period();

        return app(FattureEstereBuilder::class)->export($from, $until, $format);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(): array
    {
        return [
            Carbon::parse($this->from)->startOfDay(),
            Carbon::parse($this->until)->endOfDay(),
        ];
    }
}
