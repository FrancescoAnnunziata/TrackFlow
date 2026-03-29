<?php

namespace App\Filament\Resources\Expenses\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class ExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('client_id')
                    ->label('Cliente')
                    ->relationship(name: 'client', titleAttribute: 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                DatePicker::make('date')
                    ->label('Data')
                    ->default(now())
                    ->required(),
                TextInput::make('amount')
                    ->label('Importo')
                    ->numeric()
                    ->required()
                    ->prefix('EUR')
                    ->step(0.01),
                FileUpload::make('attachaments')
                    ->label('Allegati')
                    ->multiple()
                    ->disk('public')
                    ->directory('expense-attachaments')
                    ->visibility('public')
                    ->downloadable()
                    ->openable(),
                Textarea::make('notes')
                    ->label('Note')
                    ->columnSpanFull(),
            ]);
    }
}
