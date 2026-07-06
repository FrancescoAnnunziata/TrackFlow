<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SupplierForm
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
                            ->label('Tipo')
                            ->options([
                                'company' => 'Azienda',
                                'person' => 'Persona',
                            ])
                            ->default('company')
                            ->required(),
                        TextInput::make('vat_number')
                            ->label('Partita IVA'),
                        TextInput::make('tax_code')
                            ->label('Codice fiscale'),
                        TextInput::make('ei_code')
                            ->label('Codice destinatario (SDI)')
                            ->maxLength(16),
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
                    ]),
                Section::make('Contatti')
                    ->columns(2)
                    ->components([
                        TextInput::make('email')
                            ->label('Email')
                            ->email(),
                        TextInput::make('certified_email')
                            ->label('PEC')
                            ->email(),
                        Textarea::make('notes')
                            ->label('Note')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
