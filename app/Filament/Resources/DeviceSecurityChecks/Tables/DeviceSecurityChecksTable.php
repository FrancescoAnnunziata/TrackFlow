<?php

namespace App\Filament\Resources\DeviceSecurityChecks\Tables;

use App\Enums\SecurityOutcome;
use App\Enums\SecurityRiskLevel;
use App\Filament\Resources\DeviceSecurityChecks\Schemas\DeviceSecurityCheckInfolist;
use App\Models\DeviceSecurityCheck;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                TextColumn::make('hostname')
                    ->label('Hostname')
                    ->searchable()
                    ->placeholder('—')
                    ->description(fn (DeviceSecurityCheck $record): ?string => $record->device?->name),
                TextColumn::make('checked_at')
                    ->label('Rilevato il')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('detected_by')
                    ->label('Da')
                    ->placeholder(fn (DeviceSecurityCheck $record): string => $record->checkedBy?->name ?? '—')
                    ->toggleable(),
                // Il conteggio e' calcolato sulla riga gia' caricata: e' la
                // sintesi di quanti campi critici sono in stato di rischio.
                TextColumn::make('criticita')
                    ->label('Criticità')
                    ->badge()
                    ->state(fn (DeviceSecurityCheck $record): int => count($record->criticalIssues()))
                    ->color(fn (int $state): string => $state === 0 ? 'success' : 'danger')
                    ->tooltip(fn (DeviceSecurityCheck $record): ?string => collect($record->criticalIssues())
                        ->pluck('label')
                        ->implode(', ') ?: null),
                TextColumn::make('os_support')
                    ->label('Supporto SO')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (DeviceSecurityCheck $record): string => DeviceSecurityCheckInfolist::stateColor($record->criticalState('os_support')))
                    ->toggleable(),
                TextColumn::make('bitlocker_protection')
                    ->label('BitLocker')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (DeviceSecurityCheck $record): string => DeviceSecurityCheckInfolist::stateColor($record->criticalState('bitlocker')))
                    ->toggleable(),
                TextColumn::make('laps')
                    ->label('LAPS')
                    ->badge()
                    ->placeholder('—')
                    ->limit(20)
                    ->color(fn (DeviceSecurityCheck $record): string => DeviceSecurityCheckInfolist::stateColor($record->criticalState('laps')))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('days_since_last_patch')
                    ->label('Giorni da patch')
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('risk_level')
                    ->label('Rischio')
                    ->badge(),
                TextColumn::make('outcome')
                    ->label('Esito')
                    ->badge(),
                TextColumn::make('next_check_at')
                    ->label('Prossima')
                    ->date('d/m/Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('outcome')
                    ->label('Esito')
                    ->options(SecurityOutcome::class),
                SelectFilter::make('risk_level')
                    ->label('Rischio')
                    ->options(SecurityRiskLevel::class),
                SelectFilter::make('source')
                    ->label('Origine')
                    ->options([
                        DeviceSecurityCheck::SOURCE_INVENTORY => 'Censimento CSV',
                        DeviceSecurityCheck::SOURCE_MANUAL => 'Verifica manuale',
                    ]),
                Filter::make('non_conformi')
                    ->label('Solo con criticità')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereNot('outcome', SecurityOutcome::Compliant->value)),
                Filter::make('ultima_per_dispositivo')
                    ->label('Solo ultima rilevazione per dispositivo')
                    ->toggle()
                    // Lo storico tiene tutte le righe: questo filtro mostra la
                    // sola fotografia piu' recente di ciascun dispositivo.
                    ->query(fn (Builder $query): Builder => $query->whereRaw(
                        'device_security_checks.checked_at = (select max(checked_at) from device_security_checks as ultima where ultima.device_id = device_security_checks.device_id)'
                    )),
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
