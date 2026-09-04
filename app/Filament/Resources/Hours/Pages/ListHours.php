<?php

namespace App\Filament\Resources\Hours\Pages;

use App\Filament\Resources\Hours\HourResource;
use App\Models\Client;
use App\Models\Hour;
use App\Models\User;
use App\Services\Import\HoursExcelImporter;
use App\Services\Reporting\SpreadsheetExporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ListHours extends ListRecords
{
    protected static string $resource = HourResource::class;

    /**
     * Espone lo stato dei filtri della tabella nella query string, così i
     * filtri sono condivisibili via link (es. il link "ore della fattura").
     */
    public function queryString(): array
    {
        return [
            'tableFilters' => [],
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->exportAction(),
            $this->importAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Esporta in Excel le ore attualmente filtrate (cliente, operatore, periodo…),
     * in ordine di data. Utile per girare al cliente il riepilogo ore: filtra per
     * cliente e periodo, poi esporta.
     */
    private function exportAction(): Action
    {
        return Action::make('exportExcel')
            ->label('Esporta Excel')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()->isAdmin())
            ->action(function (): ?BinaryFileResponse {
                $hours = $this->getFilteredTableQuery()
                    ?->with(['client', 'user'])
                    ->orderBy('date')
                    ->get() ?? collect();

                if ($hours->isEmpty()) {
                    Notification::make()
                        ->warning()
                        ->title('Nessuna ora da esportare')
                        ->body('Con i filtri attuali non ci sono ore. Modifica i filtri e riprova.')
                        ->send();

                    return null;
                }

                $headings = ['Data', 'Cliente', 'Operatore', 'Ore', 'Fatturabile', 'Attività / Note'];

                $rows = $hours->map(fn (Hour $h): array => [
                    optional($h->date)->format('d/m/Y'),
                    $h->client?->name ?? '—',
                    $h->user?->name ?? $h->user?->email ?? '—',
                    (float) $h->hours,
                    $h->billable ? 'Sì' : 'No',
                    (string) ($h->notes ?? ''),
                ]);

                return SpreadsheetExporter::download('ore-'.now()->format('Y-m-d'), $headings, $rows);
            });
    }

    /**
     * Importa un timesheet Excel (.xlsx) come ore, con colonne mappabili per
     * nome intestazione. Utile per chi non registra le ore da TrackFlow.
     */
    private function importAction(): Action
    {
        return Action::make('importExcel')
            ->label('Importa Excel')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()->isAdmin())
            ->modalHeading('Importa ore da Excel')
            ->modalSubmitActionLabel('Importa')
            ->schema([
                FileUpload::make('file')
                    ->label('File Excel (.xlsx)')
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                    ->disk('local')
                    ->directory('imports')
                    ->required(),
                Select::make('user_id')
                    ->label('Utente (a chi attribuire le ore)')
                    ->options(fn (): array => User::orderBy('name')->get()->mapWithKeys(fn (User $u): array => [$u->id => "{$u->name} — {$u->email}"])->all())
                    ->searchable()
                    ->required(),
                Select::make('force_client_id')
                    ->label('Forza cliente (opzionale)')
                    ->options(fn (): array => Client::orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->helperText('Se impostato, tutte le righe vanno su questo cliente (ignora la colonna Cliente). Utile se i nomi non combaciano.'),
                Toggle::make('billable')
                    ->label('Ore fatturabili')
                    ->default(true),
                Section::make('Mappatura colonne')
                    ->description('Nome dell\'intestazione nel file per ciascun campo.')
                    ->columns(2)
                    ->components([
                        TextInput::make('col_date')->label('Colonna Data')->default('Data')->required(),
                        TextInput::make('col_hours')->label('Colonna Ore')->default('Ore')->required(),
                        TextInput::make('col_client')->label('Colonna Cliente')->default('Cliente'),
                        TextInput::make('col_notes')->label('Colonna Attività/Note')->default('Attività'),
                    ]),
            ])
            ->action(function (array $data): void {
                $relativePath = Arr::first((array) $data['file']);
                $absolute = Storage::disk('local')->path($relativePath);

                try {
                    $result = app(HoursExcelImporter::class)->import(
                        $absolute,
                        (int) $data['user_id'],
                        (bool) ($data['billable'] ?? true),
                        [
                            'date' => $data['col_date'],
                            'client' => $data['col_client'] ?? '',
                            'hours' => $data['col_hours'],
                            'notes' => $data['col_notes'] ?? '',
                        ],
                        $data['force_client_id'] ? (int) $data['force_client_id'] : null,
                    );
                } catch (Throwable $e) {
                    Notification::make()->danger()->title('Import non riuscito')->body($e->getMessage())->send();

                    return;
                } finally {
                    Storage::disk('local')->delete($relativePath);
                }

                $body = "Importate {$result['imported']} ore. Saltate: {$result['skipped']}.";
                if ($result['unmatched'] !== []) {
                    $body .= ' Clienti non trovati: '.implode(', ', $result['unmatched']).'.';
                }

                Notification::make()
                    ->success()
                    ->title('Import completato')
                    ->body($body)
                    ->send();
            });
    }
}
