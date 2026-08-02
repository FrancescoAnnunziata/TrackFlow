<?php

namespace App\Filament\Resources\TravelRates\Pages;

use App\Filament\Resources\TravelRates\TravelRateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTravelRate extends CreateRecord
{
    protected static string $resource = TravelRateResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Ogni tariffa trasferta appartiene all'utente che la crea.
        $data['user_id'] = auth()->id();

        return $data;
    }
}
