<?php

namespace App\Filament\Resources\SupportTickets\Schemas;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SupportTicketForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ticket')
                    ->columns(2)
                    ->components([
                        Select::make('client_id')
                            ->label('Azienda / Cliente')
                            ->relationship('client', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->visible(fn (): bool => ! auth()->user()->isClient()),
                        Select::make('device_id')
                            ->label('Dispositivo')
                            ->relationship('device', 'asset_code')
                            ->searchable()
                            ->preload(),
                        TextInput::make('title')
                            ->label('Titolo')
                            ->required()
                            ->columnSpanFull(),
                        Textarea::make('description')
                            ->label('Descrizione')
                            ->columnSpanFull(),
                    ]),

                Section::make('Gestione')
                    ->columns(2)
                    ->components([
                        Select::make('priority')
                            ->label('Priorità')
                            ->options(TicketPriority::class)
                            ->default(TicketPriority::Medium)
                            ->required(),
                        Select::make('status')
                            ->label('Stato')
                            ->options(TicketStatus::class)
                            ->default(TicketStatus::Open)
                            ->required()
                            ->disabled(fn (): bool => auth()->user()->isClient()),
                        Select::make('assigned_to_user_id')
                            ->label('Assegnato a')
                            ->relationship('assignedTo', 'name')
                            ->searchable()
                            ->preload()
                            ->visible(fn (): bool => ! auth()->user()->isClient()),
                    ]),
            ]);
    }
}
