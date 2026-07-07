<?php

namespace App\Filament\Resources\Devices\Schemas;

use App\Enums\DeviceCategory;
use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DeviceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identificazione')
                    ->columns(2)
                    ->components([
                        Select::make('client_id')
                            ->label('Azienda / Cliente')
                            ->relationship('client', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->visible(fn (): bool => ! auth()->user()->isClient()),
                        TextInput::make('name')
                            ->label('Nome dispositivo')
                            ->placeholder('Es. Notebook Giorgio')
                            ->required(),
                        TextInput::make('asset_code')
                            ->label('Codice asset')
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('Generato automaticamente')
                            ->helperText('Generato automaticamente alla creazione (es. G8-FED-0001).'),
                        TextInput::make('barcode')
                            ->label('Barcode')
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('Uguale al codice asset'),
                        Select::make('category')
                            ->label('Categoria')
                            ->options(DeviceCategory::class)
                            ->default(DeviceCategory::IT)
                            ->required(),
                        Select::make('type')
                            ->label('Tipo')
                            ->placeholder('Seleziona un tipo')
                            ->options(fn (): array => self::typeOptions())
                            ->searchable()
                            ->native(false)
                            // Consente di aggiungere un nuovo tipo al volo senza
                            // toccare i valori gia' presenti sui dispositivi.
                            ->createOptionForm([
                                TextInput::make('type')
                                    ->label('Nuovo tipo')
                                    ->required(),
                            ])
                            ->createOptionUsing(fn (array $data): string => $data['type']),
                    ]),

                Section::make('Hardware')
                    ->columns(2)
                    ->components([
                        TextInput::make('manufacturer')
                            ->label('Produttore'),
                        TextInput::make('model')
                            ->label('Modello'),
                        TextInput::make('serial_number')
                            ->label('Numero di serie'),
                        TextInput::make('location')
                            ->label('Ubicazione'),
                    ]),

                Section::make('Acquisto e garanzia')
                    ->columns(2)
                    ->components([
                        DatePicker::make('purchase_date')
                            ->label('Data acquisto'),
                        TextInput::make('purchase_price')
                            ->label('Prezzo acquisto')
                            ->numeric()
                            ->prefix('EUR')
                            ->step(0.01),
                        TextInput::make('supplier')
                            ->label('Fornitore'),
                        TextInput::make('invoice_number')
                            ->label('Numero fattura'),
                        DatePicker::make('warranty_until')
                            ->label('Garanzia fino al'),
                    ]),

                Section::make('Stato e assegnazione')
                    ->columns(2)
                    ->components([
                        Select::make('status')
                            ->label('Stato')
                            ->options(DeviceStatus::class)
                            ->default(DeviceStatus::InStock)
                            ->required(),
                        Select::make('assigned_user_id')
                            ->label('Assegnato a')
                            ->relationship('assignedUser', 'name')
                            ->getOptionLabelFromRecordUsing(fn (User $record): string => $record->full_name)
                            ->searchable(['name', 'surname'])
                            ->preload(),
                        DatePicker::make('next_maintenance_at')
                            ->label('Prossima manutenzione'),
                    ]),

                Section::make('Allegati')
                    ->components([
                        FileUpload::make('attachments')
                            ->label('Foto / fatture / documenti')
                            ->multiple()
                            ->disk('public')
                            ->directory('device-attachments')
                            ->visibility('public')
                            ->maxSize(20480)
                            ->downloadable()
                            ->openable()
                            ->reorderable(),
                    ]),

                Section::make('Note')
                    ->components([
                        Textarea::make('notes')
                            ->label('')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Opzioni per il campo Tipo: set predefinito piu' i tipi gia' salvati sui
     * dispositivi, cosi' i valori esistenti restano selezionabili e nessuno
     * viene perso quando si modifica un record.
     *
     * @return array<string, string>
     */
    private static function typeOptions(): array
    {
        $base = ['Monitor', 'Notebook', 'Smartphone', 'Tablet'];

        $existing = Device::query()
            ->whereNotNull('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type')
            ->all();

        return collect($base)
            ->merge($existing)
            ->unique()
            ->mapWithKeys(fn (string $type): array => [$type => $type])
            ->all();
    }
}
