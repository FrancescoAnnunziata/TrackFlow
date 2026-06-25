<?php

namespace App\Filament\Resources\DeviceMaintenances\Pages;

use App\Filament\Resources\DeviceMaintenances\DeviceMaintenanceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDeviceMaintenance extends EditRecord
{
    protected static string $resource = DeviceMaintenanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
