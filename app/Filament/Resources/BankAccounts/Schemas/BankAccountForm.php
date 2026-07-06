<?php

namespace App\Filament\Resources\BankAccounts\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class BankAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome conto')
                    ->required(),
                Select::make('bank_key')
                    ->label('Banca (preset import CSV)')
                    ->options(fn (): array => collect(config('banks.presets', []))
                        ->mapWithKeys(fn (array $preset, string $key): array => [$key => $preset['label'] ?? $key])
                        ->all())
                    ->default('generic')
                    ->required(),
                TextInput::make('iban')
                    ->label('IBAN'),
                TextInput::make('currency')
                    ->label('Valuta')
                    ->default('EUR')
                    ->maxLength(3)
                    ->required(),
                TextInput::make('opening_balance')
                    ->label('Saldo iniziale')
                    ->numeric()
                    ->prefix('EUR')
                    ->step(0.01)
                    ->default(0)
                    ->required(),
                DatePicker::make('opening_balance_date')
                    ->label('Data saldo iniziale'),
                Toggle::make('active')
                    ->label('Attivo')
                    ->default(true),
            ]);
    }
}
