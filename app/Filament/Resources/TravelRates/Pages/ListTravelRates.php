<?php

namespace App\Filament\Resources\TravelRates\Pages;

use App\Filament\Resources\TravelRates\TravelRateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTravelRates extends ListRecords
{
    protected static string $resource = TravelRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
