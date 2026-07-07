<?php

namespace App\Filament\Pages\Auth;

use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;

class NotificationPreferences extends Page
{
    public static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Preferenze notifiche';

    public ?array $data = [];

    /**
     * Solo gli utenti interni (admin/member) registrano ore: i clienti non
     * hanno accesso a questa pagina.
     */
    public static function canAccess(): bool
    {
        return ! auth()->user()?->isClient();
    }

    public function mount(): void
    {
        $this->form->fill([
            'hours_reminder_opt_in' => (bool) auth()->user()->hours_reminder_opt_in,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Toggle::make('hours_reminder_opt_in')
                    ->label('Promemoria giornaliero "segna le ore"')
                    ->helperText('Se attivo, nei giorni feriali alle 18:00 ricevi un\'email che ti ricorda di registrare le ore, ma solo se non le hai ancora inserite.'),
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
        $user->hours_reminder_opt_in = (bool) ($data['hours_reminder_opt_in'] ?? false);
        $user->save();

        Notification::make()
            ->title('Preferenze aggiornate')
            ->success()
            ->send();
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Salva')
                ->submit('form'),
        ];
    }
}
