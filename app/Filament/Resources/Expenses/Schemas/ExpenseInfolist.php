<?php

namespace App\Filament\Resources\Expenses\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ExpenseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('user.name')
                    ->label('Utente')
                    ->placeholder('-'),
                TextEntry::make('client.name')
                    ->label('Cliente')
                    ->placeholder('-'),
                TextEntry::make('date')
                    ->label('Data')
                    ->date(),
                TextEntry::make('amount')
                    ->label('Importo')
                    ->money('EUR'),
                TextEntry::make('attachaments')
                    ->label('Allegati')
                    ->formatStateUsing(function ($state): string {
                        if (! is_array($state) || $state === []) {
                            return '-';
                        }

                        return implode(', ', $state);
                    })
                    ->columnSpanFull(),
                TextEntry::make('notes')
                    ->label('Note')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
