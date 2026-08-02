<?php

namespace App\Filament\Resources\TravelRates\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TravelRateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('tipo')
                    ->label('Tipo trasferta')
                    ->helperText('La chiave da usare come "Luogo di lavoro" su Google Calendar (es. FIORAVANTI).')
                    ->required()
                    ->maxLength(255),
                TextInput::make('from_location')
                    ->label('Da')
                    ->maxLength(255),
                TextInput::make('to_location')
                    ->label('A')
                    ->maxLength(255),
                TextInput::make('purpose')
                    ->label('Oggetto')
                    ->maxLength(255)
                    ->columnSpanFull(),
                TextInput::make('km')
                    ->label('KM (andata e ritorno)')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->default(0),
            ]);
    }
}
