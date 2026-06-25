<?php

namespace App\Filament\Resources\Devices\Pages;

use App\Filament\Resources\Devices\DeviceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDevice extends CreateRecord
{
    protected static string $resource = DeviceResource::class;

    public function mount(): void
    {
        parent::mount();

        if ($serial = request()->string('serial_number')->toString()) {
            $this->form->fill([
                'serial_number' => $serial,
                'name' => $serial,
            ]);
        }
    }
}
