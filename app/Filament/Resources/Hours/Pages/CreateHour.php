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

        return $data;
    }
}
