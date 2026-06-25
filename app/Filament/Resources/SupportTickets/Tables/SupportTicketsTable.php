<?php

namespace App\Filament\Resources\SupportTickets\Tables;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SupportTicketsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label('Titolo')
                    ->searchable()
                    ->sortable()
                    ->limit(40),
                TextColumn::make('client.name')
                    ->label('Cliente')
                    ->searchable()
                    ->visible(fn (): bool => ! auth()->user()->isClient()),
                TextColumn::make('device.asset_code')
                    ->label('Dispositivo')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('priority')
                    ->label('Priorità')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Stato')
                    ->badge(),
                TextColumn::make('openedBy.name')
                    ->label('Aperto da')
                    ->toggleable(),
                TextColumn::make('assignedTo.name')
                    ->label('Assegnato a')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('opened_at')
                    ->label('Aperto il')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Stato')
                    ->options(TicketStatus::class),
                SelectFilter::make('priority')
                    ->label('Priorità')
                    ->options(TicketPriority::class),
                SelectFilter::make('assigned_to_user_id')
                    ->label('Assegnatario')
                    ->relationship('assignedTo', 'name')
                    ->searchable()
                    ->preload()
                    ->visible(fn (): bool => ! auth()->user()->isClient()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => ! auth()->user()->isClient()),
                ]),
            ]);
    }
}
