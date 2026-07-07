<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use App\Support\Impersonation;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable(),
                TextColumn::make('surname')
                    ->label('Cognome')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                TextColumn::make('role')
                    ->label('Ruolo')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'admin' => 'Admin',
                        'member' => 'Membro',
                        'client' => 'Cliente',
                        default => $state,
                    })
                    ->sortable(),
                TextColumn::make('clients.name')
                    ->label('Clienti associati')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Creato il')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('impersonate')
                    ->label('Impersona')
                    ->icon('heroicon-o-eye')
                    ->color('warning')
                    ->visible(fn (User $record): bool => auth()->user()->isAdmin()
                        && $record->isClient()
                        && $record->getKey() !== auth()->id())
                    ->requiresConfirmation()
                    ->modalHeading('Impersona cliente')
                    ->modalDescription(fn (User $record): string => "Verrai connesso come «{$record->name}» per vedere l'app dal suo punto di vista. Potrai tornare al tuo account dal banner in alto.")
                    ->modalSubmitActionLabel('Impersona')
                    ->action(function (User $record) {
                        Impersonation::start($record);

                        return redirect('/');
                    }),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
