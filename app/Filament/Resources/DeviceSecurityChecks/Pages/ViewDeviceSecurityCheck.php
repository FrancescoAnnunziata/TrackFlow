<?php

namespace App\Filament\Resources\DeviceSecurityChecks\Pages;

use App\Filament\Resources\DeviceSecurityChecks\DeviceSecurityCheckResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDeviceSecurityCheck extends ViewRecord
{
    protected static string $resource = DeviceSecurityCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (): bool => ! auth()->user()->isClient()),
        ];
    }
}
