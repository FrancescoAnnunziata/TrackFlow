<?php

namespace App\Filament\Resources\BankTransactions\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class BankTransactionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('bank_account_id')
                    ->label('Conto')
                    ->relationship(name: 'bankAccount', titleAttribute: 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                DatePicker::make('booked_at')
                    ->label('Data contabile')
                    ->required(),
                DatePicker::make('value_date')
                    ->label('Data valuta'),
                TextInput::make('amount')
                    ->label('Importo (con segno)')
                    ->numeric()
                    ->prefix('EUR')
                    ->step(0.01)
                    ->required(),
                TextInput::make('counterparty')
                    ->label('Controparte'),
                Textarea::make('description')
                    ->label('Descrizione')
                    ->columnSpanFull(),
                Textarea::make('notes')
                    ->label('Note')
                    ->columnSpanFull(),
            ]);
    }
}
