<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Models\Client;
use App\Models\User;
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
                                Client::MODEL_DAILY => 'A giornata',
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
                            ->helperText('Riga unica. Per splittarlo in più righe usa "Righe forfait" sotto.'),
                        Repeater::make('forfait_lines')
                            ->label('Righe forfait (dettaglio)')
                            ->helperText('Se compili qui, il forfait viene diviso in queste righe (importi mensili) e l\'importo unico sopra viene ignorato.')
                            ->visible(fn (Get $get): bool => $get('billing_model') === Client::MODEL_FORFAIT)
                            ->columnSpanFull()
                            ->columns(2)
                            ->schema([
                                TextInput::make('name')
                                    ->label('Descrizione')
                                    ->required(),
                                TextInput::make('amount')
                                    ->label('Importo mensile (€)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->step(0.01)
                                    ->required(),
                            ])
                            ->addActionLabel('Aggiungi riga forfait'),
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
                        TextInput::make('daily_rate')
                            ->label('Tariffa giornaliera (€/gg)')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->visible(fn (Get $get): bool => $get('billing_model') === Client::MODEL_DAILY)
                            ->requiredIf('billing_model', Client::MODEL_DAILY),
                        TextInput::make('hours_per_day')
                            ->label('Ore per giornata')
                            ->numeric()
                            ->minValue(1)
                            ->step(0.5)
                            ->default(8)
                            ->visible(fn (Get $get): bool => $get('billing_model') === Client::MODEL_DAILY)
                            ->helperText('Blocco orario di una giornata. Giornate fatturate = ore ÷ questo, arrotondate per eccesso.'),
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
                                    ->getOptionLabelFromRecordUsing(fn (User $record): string => "{$record->name} — {$record->email}")
                                    ->searchable(['name', 'email'])
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
                            ->maxLength(255)
                            ->helperText('Una o più email separate da virgola (come su Fatture in Cloud).')
                            ->rule(static fn (): \Closure => static function (string $attribute, mixed $value, \Closure $fail): void {
                                foreach (array_filter(array_map('trim', explode(',', (string) $value))) as $email) {
                                    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                        $fail("«{$email}» non è un indirizzo email valido.");
                                    }
                                }
                            }),
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
