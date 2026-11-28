<?php

namespace App\Filament\Resources\Emittenti\Schemas;

use App\Models\Emittente;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EmittenteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Intestazione')
                    ->description('Compare sui documenti: di ogni voce si stampa solo ciò che è compilato.')
                    ->columns(2)
                    ->components([
                        TextInput::make('nome')->label('Ragione sociale o nome')->required()->maxLength(255),
                        TextInput::make('sottotitolo')->label('Sottotitolo')->maxLength(255),
                        TextInput::make('partita_iva')->label('Partita IVA')->maxLength(32),
                        TextInput::make('codice_fiscale')->label('Codice fiscale')->maxLength(32),
                        TextInput::make('indirizzo')->label('Indirizzo')->maxLength(255),
                        TextInput::make('cap')->label('CAP')->maxLength(16),
                        TextInput::make('citta')->label('Città')->maxLength(255),
                        TextInput::make('provincia')->label('Provincia')->maxLength(8),
                        TextInput::make('email')->label('Email')->email()->maxLength(255),
                        TextInput::make('telefono')->label('Telefono')->maxLength(64),
                        TextInput::make('pec')->label('PEC')->email()->maxLength(255),
                        TextInput::make('iban')->label('IBAN')->maxLength(64),
                        TextInput::make('logo')
                            ->label('Logo')
                            ->maxLength(255)
                            ->helperText('Percorso del file dentro public/, ad esempio images/LogoBlack.png.'),
                        Textarea::make('nota_fiscale')
                            ->label('Dicitura fiscale')
                            ->rows(2)
                            ->helperText('Testo in calce ai documenti, ad esempio quello del regime forfettario.'),
                    ]),

                Section::make('Fatturazione')
                    ->columns(2)
                    ->components([
                        Select::make('canale_invio')
                            ->label('Canale di invio')
                            ->options(Emittente::canali())
                            ->required()
                            ->helperText('Con cosa partono oggi le fatture di questo emittente. Le fatture già emesse conservano il loro canale.'),
                        Select::make('regime_fiscale')
                            ->label('Regime abituale')
                            ->options(Emittente::regimi())
                            ->required()
                            ->helperText('Vale per ogni anno senza un\'eccezione qui sotto.'),
                        Repeater::make('regimiAnnuali')
                            ->label('Anni con un regime diverso')
                            ->relationship()
                            ->columnSpanFull()
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Aggiungi anno')
                            ->schema([
                                TextInput::make('anno')->label('Anno')->numeric()->minValue(2000)->maxValue(2100)->required(),
                                Select::make('regime_fiscale')->label('Regime')->options(Emittente::regimi())->required(),
                            ]),
                    ]),
            ]);
    }
}
