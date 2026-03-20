<?php

namespace App\Filament\Resources\Hours\Pages;

use App\Filament\Resources\Hours\HourResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHours extends ListRecords
{
    protected static string $resource = HourResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
