<?php

namespace App\Filament\Resources\DeviceMaintenances\Tables;

use App\Enums\MaintenanceType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DeviceMaintenancesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('maintenance_date', 'desc')
            ->columns([
                TextColumn::make('device.asset_code')
                    ->label('Codice')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('device.name')
                    ->label('Dispositivo')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('maintenance_date')
                    ->label('Data')
                    ->date()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge(),
                TextColumn::make('performedBy.name')
                    ->label('Eseguita da')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('cost')
                    ->label('Costo')
                    ->money('EUR')
                    ->placeholder('—'),
                TextColumn::make('next_maintenance_at')
                    ->label('Prossima')
                    ->date()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(MaintenanceType::class),
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
