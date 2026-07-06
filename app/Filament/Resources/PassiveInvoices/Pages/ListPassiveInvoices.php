<?php

namespace App\Filament\Resources\PassiveInvoices\Pages;

use App\Filament\Resources\PassiveInvoices\PassiveInvoiceResource;
use App\Models\FicCredential;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;

class ListPassiveInvoices extends ListRecords
{
    protected static string $resource = PassiveInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->importFromFicAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Lancia l'import delle fatture passive da Fatture in Cloud e mostra
     * l'output del comando. Richiede una connessione FIC attiva con lo scope
     * received_documents (vedi Impostazioni → Fatture in Cloud).
     */
    private function importFromFicAction(): Action
    {
        return Action::make('importFromFic')
            ->label('Importa da Fatture in Cloud')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()->isAdmin())
            ->modalHeading('Importa fatture passive da Fatture in Cloud')
            ->modalSubmitActionLabel('Importa')
            ->schema([
                TextInput::make('pages')
                    ->label('Pagine massime per tipo')
                    ->numeric()
                    ->default(20)
                    ->required(),
                TextInput::make('types')
                    ->label('Tipi di documento')
                    ->default('expense,passive_invoice')
                    ->helperText('Separati da virgola (es. expense, passive_invoice).')
                    ->required(),
                Toggle::make('create_suppliers')
                    ->label('Crea i fornitori mancanti')
                    ->default(true),
            ])
            ->action(function (array $data): void {
                if (! FicCredential::current()?->isConnected()) {
                    Notification::make()
                        ->danger()
                        ->title('Non collegato')
                        ->body('Collega prima TrackFlow a Fatture in Cloud da Impostazioni → Fatture in Cloud.')
                        ->send();

                    return;
                }

                $exitCode = Artisan::call('fic:import-passive-invoices', [
                    '--pages' => (int) $data['pages'],
                    '--types' => (string) $data['types'],
                    '--create-suppliers' => (bool) ($data['create_suppliers'] ?? false),
                ]);

                $output = trim(Artisan::output());

                if ($exitCode !== 0) {
                    Notification::make()->danger()->title('Import non riuscito')->body($output)->send();

                    return;
                }

                Notification::make()->success()->title('Import completato')->body($output)->send();
            });
    }
}
