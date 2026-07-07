<?php

namespace App\Filament\Pages\Auth;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ChangePassword extends Page
{
    public static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Cambio Password';

    public ?array $data = [];

    public function mount(): void
    {
        // Accessibile sia nel cambio forzato (must_change_password) sia in modo
        // volontario dal menu utente.
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Nel cambio volontario chiediamo la password attuale per
                // verificare l'identita'. Nel cambio forzato (l'utente ha appena
                // fatto login con una password temporanea) il campo e' superfluo.
                TextInput::make('currentPassword')
                    ->label('Password attuale')
                    ->password()
                    ->revealable()
                    ->currentPassword()
                    ->visible(fn (): bool => ! auth()->user()?->must_change_password)
                    ->required(fn (): bool => ! auth()->user()?->must_change_password)
                    ->dehydrated(false),
                TextInput::make('password')
                    ->label('Nuova Password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule(Password::default())
                    ->same('passwordConfirmation'),
                TextInput::make('passwordConfirmation')
                    ->label('Conferma Password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->dehydrated(false),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make($this->getFormActions())
                            ->alignment(Alignment::End)
                            ->fullWidth(),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $user = auth()->user();
        $user->password = Hash::make($data['password']);
        $user->must_change_password = false;
        $user->save();

        Notification::make()
            ->title('Password aggiornata con successo')
            ->success()
            ->send();

        redirect()->to(filament()->getUrl());
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Salva Password')
                ->submit('form'),
        ];
    }
}
