<?php

namespace App\Filament\Resources\DeviceSecurityChecks\Pages;

use App\Filament\Resources\DeviceSecurityChecks\DeviceSecurityCheckResource;
use App\Models\Client;
use App\Services\Import\EndpointInventoryCsvImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ListDeviceSecurityChecks extends ListRecords
{
    protected static string $resource = DeviceSecurityCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->downloadScriptAction(),
            $this->importAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Scarica lo script di censimento con la tabella OS_Supporto compilata
     * al volo con le date correnti (config/inventario_endpoint.php): non va
     * piu' tenuta aggiornata a mano sul file distribuito via chiavetta USB.
     */
    private function downloadScriptAction(): Action
    {
        return Action::make('downloadScript')
            ->label('Scarica script')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (): bool => ! auth()->user()->isClient())
            ->url(route('inventario.script'));
    }

    /**
     * Import del CSV prodotto dallo script PowerShell di censimento endpoint.
     *
     * Il formato e' fisso (delimitatore ";", UTF-8, intestazioni dello script),
     * quindi qui non serve la mappatura colonne dell'import bancario: basta
     * scegliere il cliente a cui attribuire i dispositivi.
     */
    private function importAction(): Action
    {
        return Action::make('import')
            ->label('Importa censimento')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('gray')
            ->visible(fn (): bool => ! auth()->user()->isClient())
            ->modalHeading('Importa il CSV di censimento endpoint')
            ->modalDescription('Ogni riga del file diventa una nuova rilevazione datata. I dispositivi vengono riconosciuti dal seriale (o dall\'hostname se il BIOS non ne espone uno valido) e la loro anagrafica viene aggiornata, non duplicata.')
            ->modalSubmitActionLabel('Importa')
            ->schema([
                Select::make('client_id')
                    ->label('Cliente')
                    ->options(fn (): array => Client::orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                FileUpload::make('file')
                    ->label('File CSV')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                    ->disk('local')
                    ->directory('imports')
                    ->required(),
            ])
            ->action(function (array $data): void {
                $relativePath = Arr::first((array) $data['file']);
                $absolute = Storage::disk('local')->path($relativePath);

                try {
                    $result = app(EndpointInventoryCsvImporter::class)
                        ->import($absolute, (int) $data['client_id'], auth()->id());
                } catch (Throwable $e) {
                    Notification::make()->danger()->title('Import non riuscito')->body($e->getMessage())->send();

                    return;
                } finally {
                    Storage::disk('local')->delete($relativePath);
                }

                $body = "Rilevazioni: {$result['checks_created']} nuove, {$result['checks_updated']} aggiornate. "
                    ."Dispositivi: {$result['devices_created']} creati, {$result['devices_updated']} aggiornati. "
                    ."Criticità aperte: {$result['findings']}.";

                if ($result['errors'] !== []) {
                    $body .= ' Righe scartate: '.$result['skipped'].' — '.implode(' | ', array_slice($result['errors'], 0, 5));
                }

                Notification::make()
                    ->title('Import completato')
                    ->body($body)
                    ->status($result['errors'] === [] ? 'success' : 'warning')
                    ->persistent()
                    ->send();
            });
    }
}
