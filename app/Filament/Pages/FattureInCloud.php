<?php

namespace App\Filament\Pages;

use App\Models\FicCredential;
use App\Services\Fic\PaymentMethods;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Pagina Impostazioni: stato della connessione a Fatture in Cloud e comandi
 * per collegare/scollegare l'account. Solo admin.
 */
class FattureInCloud extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Impostazioni';

    protected static ?string $navigationLabel = 'Fatture in Cloud';

    protected static ?string $title = 'Fatture in Cloud';

    protected string $view = 'filament.pages.fatture-in-cloud';

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function getCredential(): ?FicCredential
    {
        return FicCredential::current();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('connect')
                ->label(fn (): string => FicCredential::isConnected() ? 'Riconnetti' : 'Connetti a Fatture in Cloud')
                ->icon(Heroicon::OutlinedLink)
                ->color('primary')
                ->url(fn (): string => route('fic.connect')),

            Action::make('disconnect')
                ->label('Disconnetti')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible(fn (): bool => FicCredential::isConnected())
                ->requiresConfirmation()
                ->modalDescription('Rimuove i token salvati. Dovrai riconnettere TrackFlow per creare nuove fatture su Fatture in Cloud.')
                ->action(function (): void {
                    FicCredential::query()->delete();
                    PaymentMethods::forget();

                    Notification::make()
                        ->success()
                        ->title('Disconnesso da Fatture in Cloud')
                        ->send();
                }),
        ];
    }
}
