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
                Select::make('client_id')
                    ->label('Cliente')
                    ->helperText('Scegli il cliente per cui stai registrando le ore')
                    ->required(),
                DatePicker::make('date')
                    ->default(now())
                    ->required(),
                TextInput::make('hours')
                    ->required()
                    ->numeric(),
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
