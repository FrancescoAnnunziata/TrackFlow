<?php

namespace App\Filament\Resources\Hours\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class HourForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('clients')
                    ->label('Clienti')
                    ->relationship(name: 'clients', titleAttribute: 'name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->required()
                    ->helperText('Scegli i clienti per cui stai registrando le ore'),
                DatePicker::make('date')
                    ->default(now())
                    ->required(),
                TextInput::make('hours_part')
                    ->label('Ore')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(23)
                    ->default(0)
                    ->required(),
                TextInput::make('minutes_part')
                    ->label('Minuti')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(59)
                    ->default(0)
                    ->required(),
                Textarea::make('notes')
                    ->columnSpanFull()
                    ->label('Note')
                    ->helperText('Aggiungi eventuali note o dettagli sulle ore registrate'),
                Toggle::make('billable')
                    ->label('Fatturabile')
                    ->required(),
            ]);
    }
}
