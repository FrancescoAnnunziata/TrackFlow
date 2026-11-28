<?php

namespace App\Filament\Resources\Abbonamenti\Tables;

use App\Models\Abbonamento;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AbbonamentiTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('next_renewal_on')
                    ->label('Prossimo rinnovo')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('supplier.name')
                    ->label('Fornitore')
                    ->toggleable(),
                TextColumn::make('cadence')
                    ->label('Cadenza')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Abbonamento::cadenceOptions()[$state] ?? $state),
                TextColumn::make('amount')
                    ->label('Importo')
                    ->money('EUR')
                    ->sortable(),
                TextColumn::make('last_charged_on')
                    ->label('Ultimo addebito')
                    ->date('d/m/Y')
                    ->toggleable(),
                TextColumn::make('notes')
                    ->label('Note')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('cadence')
                    ->label('Cadenza')
                    ->options(Abbonamento::cadenceOptions()),
            ])
            ->defaultSort('next_renewal_on', 'asc')
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
