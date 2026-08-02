<?php

namespace App\Filament\Resources\TravelRates\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TravelRatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('tipo')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Utente')
                    ->searchable()
                    ->sortable()
                    ->visible(fn (): bool => auth()->user()->isAdmin()),
                TextColumn::make('tipo')
                    ->label('Tipo trasferta')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('from_location')
                    ->label('Da')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('to_location')
                    ->label('A')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('purpose')
                    ->label('Oggetto')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('km')
                    ->label('KM')
                    ->numeric()
                    ->sortable(),
            ])
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
