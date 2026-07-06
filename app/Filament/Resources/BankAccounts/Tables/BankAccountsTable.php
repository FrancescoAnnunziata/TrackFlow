<?php

namespace App\Filament\Resources\BankAccounts\Tables;

use App\Models\BankAccount;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BankAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Conto')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('bank_key')
                    ->label('Banca')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => config("banks.presets.{$state}.label", $state)),
                TextColumn::make('iban')
                    ->label('IBAN')
                    ->toggleable(),
                TextColumn::make('transactions_count')
                    ->label('Movimenti')
                    ->counts('transactions')
                    ->sortable(),
                TextColumn::make('balance')
                    ->label('Saldo')
                    ->state(fn (BankAccount $record): float => $record->currentBalance())
                    ->money('EUR'),
                IconColumn::make('active')
                    ->label('Attivo')
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
