<?php

namespace App\Filament\Resources\DeviceSecurityChecks\Pages;

use App\Filament\Resources\DeviceSecurityChecks\DeviceSecurityCheckResource;
use App\Models\DeviceSecurityCheck;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateDeviceSecurityCheck extends CreateRecord
{
    protected static string $resource = DeviceSecurityCheckResource::class;

    public function mount(): void
    {
        parent::mount();

        if ($deviceId = request()->integer('device_id')) {
            $this->form->fill(['device_id' => $deviceId, 'checked_at' => now()]);
        }
    }

    protected function afterCreate(): void
    {
        /** @var DeviceSecurityCheck $check */
        $check = $this->record;
        $check->generateFindingsForFailures();

        $count = $check->findings()->count();

        if ($count > 0) {
            Notification::make()
                ->title("Generate {$count} criticità dai controlli non superati")
                ->warning()
                ->send();
        }
    }
}
