<?php

namespace App\Filament\Resources\Devices\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'assignments';

    protected static ?string $title = 'Storico assegnazioni';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('assigned_at', 'desc')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Utente'),
                TextColumn::make('assigned_at')
                    ->label('Assegnato il')
                    ->dateTime(),
                TextColumn::make('returned_at')
                    ->label('Rientro')
                    ->dateTime()
                    ->placeholder('In corso'),
                TextColumn::make('notes')
                    ->label('Note')
                    ->limit(60)
                    ->placeholder('—'),
            ]);
    }
}
