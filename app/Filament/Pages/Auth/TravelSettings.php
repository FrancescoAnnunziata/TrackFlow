<?php

namespace App\Filament\Pages\Auth;

use App\Models\GoogleCredential;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;

class TravelSettings extends Page
{
    public static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Impostazioni trasferte';

    public ?array $data = [];

    /**
     * Solo gli utenti interni (admin/member) generano trasferte: i clienti non
     * hanno accesso a questa pagina.
     */
    public static function canAccess(): bool
    {
        return ! auth()->user()?->isClient();
    }

    public function getSubheading(): ?string
    {
        $credential = GoogleCredential::forUser(auth()->user());

        return $credential
            ? 'Google Calendar collegato'.($credential->google_email ? ' ('.$credential->google_email.')' : '').'.'
            : 'Google Calendar non collegato: collegalo per importare le trasferte dai Luoghi di lavoro.';
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        $connected = GoogleCredential::forUser(auth()->user()) !== null;

        return [
            Action::make('connectGoogle')
                ->label('Collega Google Calendar')
                ->icon('heroicon-o-calendar-days')
                ->color('primary')
                ->visible(! $connected)
                ->url(fn (): string => route('google.connect')),
            Action::make('disconnectGoogle')
                ->label('Scollega Google Calendar')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible($connected)
                ->requiresConfirmation()
                ->action(function (): void {
                    auth()->user()->googleCredential()?->delete();

                    Notification::make()->success()->title('Google Calendar scollegato')->send();
                }),
        ];
    }

    public function mount(): void
    {
        $user = auth()->user();

        $this->form->fill([
            'vehicle_plate' => $user->vehicle_plate,
            'vehicle_model' => $user->vehicle_model,
            'km_rate' => $user->km_rate,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('vehicle_plate')
                    ->label('Targa')
                    ->maxLength(255),
                TextInput::make('vehicle_model')
                    ->label('Modello auto')
                    ->maxLength(255),
                TextInput::make('km_rate')
                    ->label('Tariffa €/km')
                    ->helperText('Usata per calcolare il rimborso: KM × tariffa. Es. 0,5248.')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.0001),
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
        $user->vehicle_plate = $data['vehicle_plate'] ?? null;
        $user->vehicle_model = $data['vehicle_model'] ?? null;
        $user->km_rate = $data['km_rate'] !== null && $data['km_rate'] !== '' ? $data['km_rate'] : null;
        $user->save();

        Notification::make()
            ->title('Impostazioni trasferte aggiornate')
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
