<?php

namespace App\Filament\Resources\Devices\RelationManagers;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TicketsRelationManager extends RelationManager
{
    protected static string $relationship = 'tickets';

    protected static ?string $title = 'Ticket';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('title')
                    ->label('Titolo')
                    ->required()
                    ->columnSpanFull(),
                Select::make('priority')
                    ->label('Priorità')
                    ->options(TicketPriority::class)
                    ->default(TicketPriority::Medium)
                    ->required(),
                Select::make('status')
                    ->label('Stato')
                    ->options(TicketStatus::class)
                    ->default(TicketStatus::Open)
                    ->required(),
                Textarea::make('description')
                    ->label('Descrizione')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('title')->label('Titolo')->limit(40),
                TextColumn::make('priority')->label('Priorità')->badge(),
                TextColumn::make('status')->label('Stato')->badge(),
                TextColumn::make('assignedTo.name')->label('Assegnato a')->placeholder('—'),
                TextColumn::make('opened_at')->label('Aperto il')->dateTime(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
