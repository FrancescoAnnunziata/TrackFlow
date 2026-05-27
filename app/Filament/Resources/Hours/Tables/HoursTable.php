<?php

namespace App\Filament\Resources\Hours\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class HoursTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Utente')
                    ->searchable()
                    ->sortable()
                    ->visible(fn (): bool => auth()->user()->isAdmin()),
                TextColumn::make('clients.name')
                    ->label('Clienti')
                    ->badge()
                    ->searchable(),
                TextColumn::make('date')
                    ->date()
                    ->sortable(),
                TextColumn::make('minutes')
                    ->label('Tempo')
                    ->formatStateUsing(fn ($state): string => sprintf('%d:%02d', intdiv((int) $state, 60), ((int) $state) % 60))
                    ->sortable(),
                IconColumn::make('billable')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
