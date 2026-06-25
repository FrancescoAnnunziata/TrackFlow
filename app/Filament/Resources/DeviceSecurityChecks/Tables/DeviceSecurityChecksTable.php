<?php

namespace App\Filament\Resources\DeviceSecurityChecks\Tables;

use App\Enums\SecurityOutcome;
use App\Enums\SecurityRiskLevel;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DeviceSecurityChecksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('checked_at', 'desc')
            ->columns([
                TextColumn::make('device.asset_code')
                    ->label('Codice')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('device.name')
                    ->label('Dispositivo')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('checked_at')
                    ->label('Verificato il')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('checkedBy.name')
                    ->label('Verificato da')
                    ->toggleable(),
                TextColumn::make('risk_level')
                    ->label('Rischio')
                    ->badge(),
                TextColumn::make('outcome')
                    ->label('Esito')
                    ->badge(),
                TextColumn::make('next_check_at')
                    ->label('Prossima')
                    ->date()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('outcome')
                    ->label('Esito')
                    ->options(SecurityOutcome::class),
                SelectFilter::make('risk_level')
                    ->label('Rischio')
                    ->options(SecurityRiskLevel::class),
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
