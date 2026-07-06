<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name')
                    ->label('Nome'),
                TextEntry::make('surname')
                    ->label('Cognome'),
                TextEntry::make('email')
                    ->label('Email'),
                TextEntry::make('role')
                    ->label('Ruolo')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'admin' => 'Admin',
                        'controller' => 'Controller',
                        'member' => 'Membro',
                        'client' => 'Cliente',
                        default => $state,
                    }),
                TextEntry::make('created_at')
                    ->label('Creato il')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->label('Aggiornato il')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
