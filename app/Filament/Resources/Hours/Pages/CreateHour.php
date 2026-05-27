<?php

namespace App\Filament\Resources\Hours\Pages;

use App\Filament\Resources\Hours\HourResource;
use Filament\Resources\Pages\CreateRecord;

class CreateHour extends CreateRecord
{
    protected static string $resource = HourResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        $data['minutes'] = ((int) ($data['hours_part'] ?? 0)) * 60
            + ((int) ($data['minutes_part'] ?? 0));

        unset($data['hours_part'], $data['minutes_part']);

        return $data;
    }
}
