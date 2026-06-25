<?php

namespace App\Filament\Resources\DeviceMaintenances\Pages;

use App\Filament\Resources\DeviceMaintenances\DeviceMaintenanceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDeviceMaintenances extends ListRecords
{
    protected static string $resource = DeviceMaintenanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
