<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Models\Client;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Anagrafica')
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->label('Ragione sociale / Nome')
                            ->required()
                            ->columnSpanFull(),
                        Select::make('entity_type')
                            ->label('Tipo soggetto')
                            ->options([
                                'company' => 'Azienda',
                                'person' => 'Privato / Persona fisica',
                            ])
                            ->default('company')
                            ->required(),
                        TextInput::make('asset_prefix')
                            ->label('Prefisso asset')
                            ->maxLength(8)
                            ->placeholder('Es. FED')
                            ->helperText('Usato nei codici asset (G8-FED-0001). Se vuoto, derivato dal nome.'),
                        FileUpload::make('logo')
                            ->image()
                            ->disk('public')
                            ->directory('client-logos')
                            ->visibility('public')
                            ->imageEditor()
                            ->maxSize(2048),
                    ]),

                Section::make('Dati fiscali')
                    ->columns(2)
                    ->components([
                        TextInput::make('vat_number')
                            ->label('Partita IVA')
                            ->maxLength(32),
                        TextInput::make('tax_code')
                            ->label('Codice Fiscale')
                            ->maxLength(32),
                        TextInput::make('ei_code')
                            ->label('Codice destinatario (SDI)')
                            ->maxLength(16)
                            ->helperText('7 caratteri per privati, "0000000" per default'),
                        TextInput::make('certified_email')
                            ->label('PEC')
                            ->email()
                            ->maxLength(255),
                    ]),

                Section::make('Fatturazione')
                    ->columns(2)
                    ->components([
                        Select::make('invoicing_provider')
                            ->label('Provider fatturazione')
                            ->options([
                                'fatture_in_cloud' => 'Fatture in Cloud',
                                'fiscozen' => 'Fiscozen (no API)',
                                'altro' => 'Altro / esterno',
                            ])
                            ->default('fatture_in_cloud')
                            ->required()
                            ->helperText('Solo i clienti su Fatture in Cloud sono fatturabili da TrackFlow.'),
                        Select::make('billing_model')
                            ->label('Modello')
                            ->options([
                                Client::MODEL_HOURLY => 'A ore',
                                Client::MODEL_FORFAIT => 'Forfait (importo fisso)',
                            ])
                            ->default(Client::MODEL_HOURLY)
                            ->required()
                            ->live(),
                        Select::make('billing_period_months')
                            ->label('Periodicità')
                            ->options([
                                1 => 'Mensile',
                                3 => 'Trimestrale',
                                6 => 'Semestrale',
                                12 => 'Annuale',
                            ])
                            ->default(1)
                            ->required(),
                        Select::make('billing_timing')
                            ->label('Timing')
                            ->options([
                                Client::TIMING_ARREARS => 'Posticipato',
                                Client::TIMING_ADVANCE => 'Anticipato',
                            ])
                            ->default(Client::TIMING_ARREARS)
                            ->required()
                            ->live(),
                        Toggle::make('reconcile_previous_period')
                            ->label('Conguaglia il periodo precedente')
                            ->helperText('Anticipato + conguaglio ore extra e spese del periodo scorso (es. Alsea).')
                            ->visible(fn (Get $get): bool => $get('billing_timing') === Client::TIMING_ADVANCE),

                        TextInput::make('forfait_amount')
                            ->label('Importo forfait (€/mese)')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->visible(fn (Get $get): bool => $get('billing_model') === Client::MODEL_FORFAIT)
                            ->requiredIf('billing_model', Client::MODEL_FORFAIT),
                        TextInput::make('default_hourly_rate')
                            ->label('Tariffa oraria di default (€/h)')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->visible(fn (Get $get): bool => $get('billing_model') === Client::MODEL_HOURLY)
                            ->helperText('Usata per gli utenti senza tariffa specifica sotto.'),
                        TextInput::make('minimum_hours_per_month')
                            ->label('Minimo ore garantite/mese')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.5)
                            ->visible(fn (Get $get): bool => $get('billing_model') === Client::MODEL_HOURLY)
                            ->helperText('Lascia vuoto se non c\'è minimo garantito.'),
                        TextInput::make('monthly_extra_amount')
                            ->label('Extra fisso (€/mese)')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->helperText('Voce ricorrente aggiuntiva, se prevista.'),

                        TextInput::make('vat_rate')
                            ->label('IVA (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.01)
                            ->default(22)
                            ->required(),
                        TextInput::make('consulting_label')
                            ->label('Etichetta riga consulenza')
                            ->maxLength(255)
                            ->placeholder('Es. Consulenza digitale')
                            ->helperText('Titolo base delle righe consulenza in fattura.'),
                        TextInput::make('payment_method_id')
                            ->label('ID metodo di pagamento FIC')
                            ->numeric()
                            ->helperText('Vuoto = usa il metodo predefinito su Fatture in Cloud.'),

                        Repeater::make('userRates')
                            ->label('Tariffe per utente')
                            ->relationship()
                            ->columnSpanFull()
                            ->columns(2)
                            ->schema([
                                Select::make('user_id')
                                    ->label('Utente')
                                    ->relationship('user', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required(),
                                TextInput::make('hourly_rate')
                                    ->label('Tariffa (€/h)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->step(0.01)
                                    ->required(),
                            ])
                            ->addActionLabel('Aggiungi tariffa utente')
                            ->visible(fn (Get $get): bool => $get('billing_model') === Client::MODEL_HOURLY),
                    ]),

                Section::make('Indirizzo')
                    ->columns(2)
                    ->components([
                        TextInput::make('address_street')
                            ->label('Indirizzo')
                            ->columnSpanFull(),
                        TextInput::make('address_postal_code')
                            ->label('CAP')
                            ->maxLength(16),
                        TextInput::make('address_city')
                            ->label('Città'),
                        TextInput::make('address_province')
                            ->label('Provincia')
                            ->maxLength(8),
                        TextInput::make('country')
                            ->label('Paese')
                            ->default('Italia'),
                        TextInput::make('country_iso')
                            ->label('Codice ISO paese')
                            ->maxLength(2)
                            ->default('IT'),
                    ]),

                Section::make('Contatti')
                    ->columns(2)
                    ->components([
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),
                    ]),

                Section::make('Note')
                    ->components([
                        Textarea::make('notes')
                            ->label('')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
