<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Filament\Resources\Clients\Schemas\ClientForm;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                TextInput::make('surname')
                    ->label('Cognome')
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Select::make('role')
                    ->label('Ruolo')
                    ->options([
                        'admin' => 'Admin',
                        'member' => 'Membro',
                        'client' => 'Cliente',
                        'accountant' => 'Commercialista (assistente AI in sola lettura)',
                    ])
                    ->default('member')
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        if ($state !== 'client') {
                            $set('client_id', null);
                            $set('clients', []);
                        }
                    }),
                Select::make('client_id')
                    ->label('Cliente principale')
                    ->helperText('Cliente predefinito usato alla creazione di nuovi record. Viene incluso automaticamente tra i clienti associati.')
                    ->relationship('client', 'name')
                    ->searchable()
                    ->preload()
                    ->live()
                    ->visible(fn (Get $get): bool => $get('role') === 'client')
                    ->required(fn (Get $get): bool => $get('role') === 'client')
                    // Il cliente principale fa sempre parte delle associazioni.
                    ->afterStateUpdated(function (Set $set, Get $get, $state): void {
                        if (! $state) {
                            return;
                        }

                        $clients = collect($get('clients') ?? [])
                            ->push($state)
                            ->unique()
                            ->values()
                            ->all();

                        $set('clients', $clients);
                    })
                    ->createOptionForm(fn (Schema $schema): Schema => ClientForm::configure($schema)),
                Select::make('clients')
                    ->label('Clienti associati')
                    ->helperText('L\'utente vedrà i dati di tutti i clienti selezionati.')
                    ->relationship('clients', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->visible(fn (Get $get): bool => $get('role') === 'client'),
                TextInput::make('password')
                    ->label('Password')
                    ->password()
                    ->revealable()
                    // I clienti accedono via magic link: nessuna password richiesta.
                    ->required(fn (string $context, Get $get): bool => $context === 'create' && $get('role') !== 'client')
                    ->helperText(fn (Get $get): ?string => $get('role') === 'client'
                        ? 'I clienti accedono via magic link dal preventivo: lasciare vuoto.'
                        : null)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->rule(Password::default()),
            ]);
    }
}
