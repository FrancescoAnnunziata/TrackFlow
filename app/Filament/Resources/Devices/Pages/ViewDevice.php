<?php

namespace App\Filament\Resources\Devices\Pages;

use App\Filament\Resources\Devices\DeviceResource;
use App\Models\Device;
use App\Models\DeviceSecurityCheck;
use App\Services\Security\EndpointHistory;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

class ViewDevice extends ViewRecord
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->securityTimelineAction(),
            Action::make('label')
                ->label('Stampa etichetta')
                ->icon(Heroicon::OutlinedQrCode)
                ->color('gray')
                ->url(fn (Device $record): string => route('assets.label', $record), shouldOpenInNewTab: true),
            EditAction::make()
                ->visible(fn (): bool => ! auth()->user()->isClient()),
        ];
    }

    /**
     * Andamento dei campi critici: elenca solo i momenti in cui lo stato e'
     * cambiato (es. "LAPS da a rischio a a posto il 15/09"), non tutte le
     * rilevazioni.
     */
    private function securityTimelineAction(): Action
    {
        return Action::make('securityTimeline')
            ->label('Andamento sicurezza')
            ->icon(Heroicon::OutlinedChartBar)
            ->color('gray')
            ->modalHeading('Andamento dei campi critici')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Chiudi')
            ->modalContent(function (Device $record): View {
                $history = app(EndpointHistory::class);

                $timeline = collect(DeviceSecurityCheck::CRITICAL_CHECKS)
                    ->map(fn (array $definition, string $key): array => [
                        'label' => $definition[0],
                        'transitions' => $history->transitions($record, $key),
                    ])
                    ->all();

                return view('filament.devices.security-timeline', ['timeline' => $timeline]);
            });
    }
}
