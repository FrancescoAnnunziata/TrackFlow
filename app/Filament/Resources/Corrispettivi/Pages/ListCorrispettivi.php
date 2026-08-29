<?php

namespace App\Filament\Resources\Corrispettivi\Pages;

use App\Filament\Resources\Corrispettivi\CorrispettivoResource;
use App\Services\Shopify\CorrispettiviSync;
use App\Services\Shopify\ShopifyClient;
use App\Services\Shopify\ShopifyException;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;

class ListCorrispettivi extends ListRecords
{
    protected static string $resource = CorrispettivoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')
                ->label('Sincronizza da Shopify')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn () => ShopifyClient::fromConfig()->isConfigured())
                ->schema([
                    TextInput::make('days')
                        ->label('Giorni da risincronizzare')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(400)
                        ->default((int) config('services.shopify.resync_days', 14))
                        ->helperText('Riscrive i giorni indicati fino a oggi. Le righe manuali non vengono toccate.'),
                ])
                ->action(function (array $data): void {
                    $to = Carbon::today();
                    $from = $to->copy()->subDays(max(1, (int) ($data['days'] ?? 14)));

                    try {
                        $righe = CorrispettiviSync::make()->sync($from, $to);
                    } catch (ShopifyException $e) {
                        Notification::make()
                            ->danger()
                            ->title('Sincronizzazione fallita')
                            ->body($e->getMessage())
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title('Incassi aggiornati')
                        ->body(sprintf(
                            '%d giorni aggiornati · netto del periodo € %s',
                            $righe->count(),
                            number_format($righe->sum(fn ($riga) => $riga->net), 2, ',', '.'),
                        ))
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
