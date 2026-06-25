<?php

namespace App\Filament\Resources\DeviceSecurityChecks\Pages;

use App\Filament\Resources\DeviceSecurityChecks\DeviceSecurityCheckResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDeviceSecurityChecks extends ListRecords
{
    protected static string $resource = DeviceSecurityCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
