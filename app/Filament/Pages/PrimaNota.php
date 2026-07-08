<?php

namespace App\Filament\Pages;

use App\Models\BankAccount;
use App\Services\Reporting\PrimaNotaBuilder;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Prima nota (banca): registro cronologico dei movimenti bancari con saldo
 * progressivo, filtrabile per periodo e conto ed esportabile. Solo admin.
 */
class PrimaNota extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|\UnitEnum|null $navigationGroup = 'Controllo Finanziario';

    protected static ?string $title = 'Prima nota';

    protected static ?string $navigationLabel = 'Prima nota';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.prima-nota';

    public string $from;

    public string $until;

    public ?int $bankAccountId = null;

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
     * @return array<int, string>
     */
    public function accountOptions(): array
    {
        return BankAccount::orderBy('id')->pluck('name', 'id')->all();
    }

    /**
     * @return array{headings: array<int, string>, rows: array<int, array{kind: string, cells: array<int, mixed>}>}
     */
    public function tableData(): array
    {
        return app(PrimaNotaBuilder::class)->build(
            Carbon::parse($this->from)->startOfDay(),
            Carbon::parse($this->until)->endOfDay(),
            $this->bankAccountId ?: null,
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
        return app(PrimaNotaBuilder::class)->export(
            Carbon::parse($this->from)->startOfDay(),
            Carbon::parse($this->until)->endOfDay(),
            $this->bankAccountId ?: null,
            $format,
        );
    }
}
