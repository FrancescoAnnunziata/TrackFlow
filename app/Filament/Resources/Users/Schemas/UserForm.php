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
                    ])
                    ->default('member')
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        if ($state !== 'client') {
                            $set('client_id', null);
                        }
                    }),
                Select::make('client_id')
                    ->label('Cliente associato')
                    ->relationship('client', 'name')
                    ->searchable()
                    ->preload()
                    ->visible(fn (Get $get): bool => $get('role') === 'client')
                    ->required(fn (Get $get): bool => $get('role') === 'client')
                    ->createOptionForm(fn (Schema $schema): Schema => ClientForm::configure($schema)),
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
