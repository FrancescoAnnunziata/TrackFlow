<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use App\Support\Impersonation;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
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
                IconColumn::make('app_authentication_secret')
                    ->label('2FA')
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-lock-open')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->tooltip(fn (User $record): string => $record->getAppAuthenticationSecret()
                        ? 'App di autenticazione collegata'
                        : 'Non ancora configurata: al prossimo accesso gliela chiede'),
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
                // Via d'uscita quando un utente perde sia il telefono sia i
                // codici di recupero: senza questa azione l'unico rimedio e' una
                // UPDATE a mano sul database di produzione. Azzerare il segreto
                // non gli apre l'accesso — al login successivo la 2FA gli viene
                // richiesta di nuovo da capo, con un'app nuova.
                Action::make('resetTwoFactor')
                    ->label('Azzera 2FA')
                    ->icon('heroicon-o-lock-open')
                    ->color('danger')
                    ->visible(fn (User $record): bool => auth()->user()->isAdmin()
                        && filled($record->getAppAuthenticationSecret()))
                    ->requiresConfirmation()
                    ->modalHeading('Azzera i due fattori')
                    ->modalDescription(fn (User $record): string => "«{$record->name}» dovra' ricollegare un'app di autenticazione al prossimo accesso. Fallo solo dopo aver verificato di persona chi te lo sta chiedendo: e' il punto in cui la 2FA si aggira con una telefonata.")
                    ->modalSubmitActionLabel('Azzera')
                    ->action(function (User $record) {
                        $record->saveAppAuthenticationSecret(null);
                        $record->saveAppAuthenticationRecoveryCodes(null);

                        Notification::make()
                            ->success()
                            ->title('Due fattori azzerati')
                            ->body("«{$record->name}» dovra' riconfigurarli al prossimo accesso.")
                            ->send();
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
