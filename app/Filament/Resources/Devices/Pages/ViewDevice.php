<?php

namespace App\Filament\Resources\Devices\Pages;

use App\Filament\Resources\Devices\DeviceResource;
use App\Models\Device;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewDevice extends ViewRecord
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('label')
                ->label('Stampa etichetta')
                ->icon(Heroicon::OutlinedQrCode)
                ->color('gray')
                ->url(fn (Device $record): string => route('assets.label', $record), shouldOpenInNewTab: true),
            EditAction::make()
                ->visible(fn (): bool => ! auth()->user()->isClient()),
        ];
    }
}
