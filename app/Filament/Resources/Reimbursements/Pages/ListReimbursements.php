<?php

namespace App\Filament\Resources\Reimbursements\Pages;

use App\Filament\Resources\Reimbursements\ReimbursementResource;
use App\Models\Client;
use App\Models\GoogleCredential;
use App\Models\TravelRate;
use App\Services\Google\GoogleCalendarImporter;
use App\Services\Google\GoogleException;
use App\Services\TravelReimbursementService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;

class ListReimbursements extends ListRecords
{
    protected static string $resource = ReimbursementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->importGoogleAction(),
            $this->generateTravelAction(),
            $this->exportAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Genera in automatico le trasferte del mese leggendo i Luoghi di lavoro da
     * Google Calendar e abbinandoli alla tabella trasferte dell'utente.
     */
    private function importGoogleAction(): Action
    {
        $now = now();

        return Action::make('importGoogle')
            ->label('Importa da Google Calendar')
            ->icon('heroicon-o-calendar-days')
            ->color('gray')
            ->visible(fn (): bool => GoogleCredential::forUser(auth()->user()) !== null)
            ->schema([
                Select::make('month')
                    ->label('Mese')
                    ->options([
                        1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
                        5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
                        9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre',
                    ])
                    ->default($now->month)
                    ->required(),
                Select::make('year')
                    ->label('Anno')
                    ->options(array_combine(
                        range($now->year, $now->year - 4),
                        range($now->year, $now->year - 4),
                    ))
                    ->default($now->year)
                    ->required(),
            ])
            ->action(function (array $data, GoogleCalendarImporter $importer): void {
                try {
                    $result = $importer->importMonth(auth()->user(), (int) $data['year'], (int) $data['month']);
                } catch (GoogleException $e) {
                    Notification::make()->danger()->title('Import fallito')->body($e->getMessage())->send();

                    return;
                }

                $notification = Notification::make()
                    ->title($result['generated'] > 0
                        ? $result['generated'].' trasferte generate'
                        : 'Nessuna trasferta generata');

                if ($result['unmatched'] !== []) {
                    $labels = implode(', ', array_keys($result['unmatched']));
                    $notification
                        ->warning()
                        ->body('Etichette senza corrispondenza in tabella: '.$labels.'. Aggiungile alla Tabella trasferte e reimporta.');
                } else {
                    $notification->success();
                }

                $notification->send();
            });
    }

    /**
     * Genera un rimborso trasferta scegliendo giorno e "Tipo trasferta" dalla
     * tabella dell'utente. In Fase 2 questo passaggio verra' fatto in automatico
     * leggendo i Luoghi di lavoro da Google Calendar.
     */
    private function generateTravelAction(): Action
    {
        return Action::make('generateTravel')
            ->label('Genera trasferta')
            ->icon('heroicon-o-map-pin')
            ->color('gray')
            ->visible(fn (): bool => TravelRate::where('user_id', auth()->id())->exists())
            ->schema([
                DatePicker::make('date')
                    ->label('Giorno')
                    ->default(now())
                    ->required(),
                Select::make('travel_rate_id')
                    ->label('Tipo trasferta')
                    ->options(fn (): array => TravelRate::where('user_id', auth()->id())
                        ->orderBy('tipo')
                        ->pluck('tipo', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data, TravelReimbursementService $service): void {
                $rate = TravelRate::where('user_id', auth()->id())->findOrFail($data['travel_rate_id']);

                $service->generate(auth()->user(), $rate, Carbon::parse($data['date']));

                Notification::make()
                    ->title('Trasferta generata')
                    ->success()
                    ->send();
            });
    }

    private function exportAction(): Action
    {
        $now = now();

        return Action::make('export')
            ->label('Esporta nota spese')
            ->icon('heroicon-o-arrow-down-tray')
            ->schema([
                Select::make('month')
                    ->label('Mese')
                    ->options([
                        1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
                        5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
                        9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre',
                    ])
                    ->default($now->month)
                    ->required(),
                Select::make('year')
                    ->label('Anno')
                    ->options(array_combine(
                        range($now->year, $now->year - 4),
                        range($now->year, $now->year - 4),
                    ))
                    ->default($now->year)
                    ->required(),
                Select::make('client_id')
                    ->label('Azienda (opzionale)')
                    ->helperText('Se selezionata, include solo trasferte e spese di quel cliente.')
                    ->options(fn (): array => Client::orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
            ])
            ->action(fn (array $data) => redirect()->route('reimbursements.export', array_filter([
                'month' => $data['month'],
                'year' => $data['year'],
                'client_id' => $data['client_id'] ?? null,
            ], fn ($v) => $v !== null && $v !== '')));
    }
}
