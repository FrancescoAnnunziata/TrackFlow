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
        if (! auth()->user()?->must_change_password) {
            redirect()->to(filament()->getUrl());
        }

        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
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
