<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Models\User;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Utenti associati';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (User $record): string => $record->full_name)
            ->columns([
                TextColumn::make('full_name')
                    ->label('Nome')
                    ->searchable(['name', 'surname']),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                TextColumn::make('role')
                    ->label('Ruolo')
                    ->badge(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Associa utente')
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name', 'surname', 'email']),
            ])
            ->recordActions([
                DetachAction::make()
                    ->label('Rimuovi'),
            ]);
    }
}
