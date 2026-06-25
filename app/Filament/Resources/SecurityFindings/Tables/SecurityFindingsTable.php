<?php

namespace App\Filament\Resources\SecurityFindings\Tables;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Models\SecurityFinding;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SecurityFindingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('device.asset_code')
                    ->label('Codice')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Criticità')
                    ->searchable()
                    ->limit(50),
                TextColumn::make('severity')
                    ->label('Severità')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Stato')
                    ->badge(),
                TextColumn::make('due_date')
                    ->label('Scadenza')
                    ->date()
                    ->color(fn (?SecurityFinding $record) => $record?->due_date && $record->due_date->isPast() && $record->status !== FindingStatus::Resolved ? 'danger' : null)
                    ->toggleable(),
                TextColumn::make('resolvedBy.name')
                    ->label('Risolto da')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('resolved_at')
                    ->label('Risolto il')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('severity')
                    ->label('Severità')
                    ->options(FindingSeverity::class),
                SelectFilter::make('status')
                    ->label('Stato')
                    ->options(FindingStatus::class),
            ])
            ->recordActions([
                Action::make('resolve')
                    ->label('Risolvi')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (SecurityFinding $record): bool => ! auth()->user()->isClient() && $record->status !== FindingStatus::Resolved)
                    ->requiresConfirmation()
                    ->action(function (SecurityFinding $record): void {
                        $record->update([
                            'status' => FindingStatus::Resolved,
                            'resolved_at' => now(),
                            'resolved_by_user_id' => auth()->id(),
                        ]);
                        Notification::make()->title('Criticità risolta')->success()->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
