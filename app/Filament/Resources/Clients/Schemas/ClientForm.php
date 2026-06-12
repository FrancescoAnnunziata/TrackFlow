<?php

namespace App\Filament\Resources\Clients\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
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
