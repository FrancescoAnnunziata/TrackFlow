<?php

namespace App\Filament\Resources\SupportTickets\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    protected static ?string $title = 'Commenti';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('body')
                    ->label('Messaggio')
                    ->required()
                    ->columnSpanFull(),
                Toggle::make('internal_note')
                    ->label('Nota interna (non visibile al cliente)')
                    ->visible(fn (): bool => ! auth()->user()->isClient()),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('body')
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => auth()->user()->isClient()
                ? $query->where('internal_note', false)
                : $query)
            ->columns([
                TextColumn::make('user.name')
                    ->label('Autore'),
                TextColumn::make('body')
                    ->label('Messaggio')
                    ->wrap()
                    ->limit(120),
                IconColumn::make('internal_note')
                    ->label('Interna')
                    ->boolean()
                    ->visible(fn (): bool => ! auth()->user()->isClient()),
                TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn ($record): bool => auth()->user()->isAdmin() || (int) $record->user_id === (int) auth()->id()),
                DeleteAction::make()
                    ->visible(fn ($record): bool => auth()->user()->isAdmin() || (int) $record->user_id === (int) auth()->id()),
            ]);
    }
}
