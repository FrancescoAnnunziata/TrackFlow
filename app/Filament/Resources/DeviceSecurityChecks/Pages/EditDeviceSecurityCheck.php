<?php

namespace App\Filament\Resources\DeviceSecurityChecks\Pages;

use App\Filament\Resources\DeviceSecurityChecks\DeviceSecurityCheckResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDeviceSecurityCheck extends EditRecord
{
    protected static string $resource = DeviceSecurityCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
