<?php

namespace App\Filament\Resources\TravelRates\Pages;

use App\Filament\Resources\TravelRates\TravelRateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTravelRate extends EditRecord
{
    protected static string $resource = TravelRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
