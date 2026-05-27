<?php

namespace App\Filament\Resources\Hours\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class HourInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('user.name')
                    ->label('User'),
                TextEntry::make('clients.name')
                    ->label('Clienti')
                    ->badge()
                    ->placeholder('-'),
                TextEntry::make('date')
                    ->date(),
                TextEntry::make('minutes')
                    ->label('Tempo')
                    ->formatStateUsing(fn ($state): string => sprintf('%d:%02d', intdiv((int) $state, 60), ((int) $state) % 60)),
                TextEntry::make('notes')
                    ->placeholder('-')
                    ->columnSpanFull(),
                IconEntry::make('billable')
                    ->boolean(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
