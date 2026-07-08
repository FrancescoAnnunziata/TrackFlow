<?php

namespace App\Filament\Pages;

use App\Services\Reporting\RegistroAcquistiBuilder;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Registro acquisti/spese per il commercialista: elenco dei costi del periodo
 * (spese, costi, fatture passive standalone) con conto, giustificativo e
 * riaddebito, esportabile in Excel/CSV. Solo admin.
 */
class RegistroAcquisti extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'Controllo Finanziario';

    protected static ?string $title = 'Registro acquisti';

    protected static ?string $navigationLabel = 'Registro acquisti';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.registro-acquisti';

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
     * @return array{headings: array<int, string>, rows: array<int, array{kind: string, cells: array<int, mixed>}>}
     */
    public function tableData(): array
    {
        return app(RegistroAcquistiBuilder::class)->build(
            Carbon::parse($this->from)->startOfDay(),
            Carbon::parse($this->until)->endOfDay(),
        );
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
        return app(RegistroAcquistiBuilder::class)->export(
            Carbon::parse($this->from)->startOfDay(),
            Carbon::parse($this->until)->endOfDay(),
            $format,
        );
    }
}
