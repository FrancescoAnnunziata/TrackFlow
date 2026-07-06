<?php

namespace App\Filament\Resources\BankTransactions\Pages;

use App\Filament\Resources\BankTransactions\BankTransactionResource;
use App\Models\BankAccount;
use App\Services\Import\BankCsvImporter;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ListBankTransactions extends ListRecords
{
    protected static string $resource = BankTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->importCsvAction(),
        ];
    }

    /**
     * Importa un estratto conto CSV come movimenti. La mappatura colonne e il
     * formato numerico/data vengono pre-compilati dal preset della banca del
     * conto selezionato (config/banks.php), e restano modificabili.
     */
    private function importCsvAction(): Action
    {
        return Action::make('importCsv')
            ->label('Importa CSV')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()->isAdmin())
            ->modalHeading('Importa movimenti da CSV')
            ->modalSubmitActionLabel('Importa')
            ->schema([
                Select::make('bank_account_id')
                    ->label('Conto')
                    ->options(fn (): array => BankAccount::orderBy('name')->pluck('name', 'id')->all())
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, Set $set): void {
                        $account = BankAccount::find($state);
                        if ($account === null) {
                            return;
                        }
                        $preset = config('banks.presets.'.$account->bank_key)
                            ?? config('banks.presets.generic');

                        $set('delimiter', $preset['delimiter']);
                        $set('decimal', $preset['decimal']);
                        $set('thousands', $preset['thousands']);
                        $set('date_format', $preset['date_format']);
                        $set('amount_mode', $preset['amount_mode']);
                        foreach ($preset['columns'] as $field => $header) {
                            $set('col_'.$field, $header);
                        }
                    }),
                FileUpload::make('file')
                    ->label('File CSV')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                    ->disk('local')
                    ->directory('imports')
                    ->required(),
                Section::make('Formato file')
                    ->columns(3)
                    ->components([
                        TextInput::make('delimiter')->label('Separatore')->default(';')->required(),
                        TextInput::make('decimal')->label('Decimale')->default(',')->required(),
                        TextInput::make('thousands')->label('Migliaia')->default('.'),
                        TextInput::make('date_format')->label('Formato data')->default('d/m/Y')->required(),
                        Select::make('amount_mode')
                            ->label('Modalità importo')
                            ->options([
                                'signed' => 'Colonna unica con segno',
                                'dare_avere' => 'Colonne Dare/Avere',
                            ])
                            ->default('signed')
                            ->required(),
                    ]),
                Section::make('Mappatura colonne')
                    ->description('Nome dell\'intestazione nel file per ciascun campo (vuoto = ignora).')
                    ->columns(2)
                    ->components([
                        TextInput::make('col_booked_at')->label('Data contabile')->default('Data')->required(),
                        TextInput::make('col_value_date')->label('Data valuta'),
                        TextInput::make('col_amount')->label('Importo (modalità con segno)'),
                        TextInput::make('col_dare')->label('Dare (uscite)'),
                        TextInput::make('col_avere')->label('Avere (entrate)'),
                        TextInput::make('col_description')->label('Descrizione'),
                        TextInput::make('col_counterparty')->label('Controparte'),
                        TextInput::make('col_reference')->label('Riferimento (ID movimento)'),
                    ]),
            ])
            ->action(function (array $data): void {
                $relativePath = Arr::first((array) $data['file']);
                $absolute = Storage::disk('local')->path($relativePath);

                $options = [
                    'delimiter' => $data['delimiter'] ?: ';',
                    'decimal' => $data['decimal'] ?: ',',
                    'thousands' => $data['thousands'] ?? '',
                    'date_format' => $data['date_format'] ?: 'd/m/Y',
                    'amount_mode' => $data['amount_mode'] ?? 'signed',
                    'columns' => [
                        'booked_at' => $data['col_booked_at'] ?? '',
                        'value_date' => $data['col_value_date'] ?? '',
                        'amount' => $data['col_amount'] ?? '',
                        'dare' => $data['col_dare'] ?? '',
                        'avere' => $data['col_avere'] ?? '',
                        'description' => $data['col_description'] ?? '',
                        'counterparty' => $data['col_counterparty'] ?? '',
                        'reference' => $data['col_reference'] ?? '',
                    ],
                ];

                try {
                    $result = app(BankCsvImporter::class)->import($absolute, (int) $data['bank_account_id'], $options);
                } catch (Throwable $e) {
                    Notification::make()->danger()->title('Import non riuscito')->body($e->getMessage())->send();

                    return;
                } finally {
                    Storage::disk('local')->delete($relativePath);
                }

                Notification::make()
                    ->success()
                    ->title('Import completato')
                    ->body("Importati {$result['imported']} movimenti. Duplicati saltati: {$result['duplicates']}. Scartati: {$result['skipped']}.")
                    ->send();
            });
    }
}
