<?php

namespace App\Filament\Resources\Costi\Pages;

use App\Filament\Resources\Costi\CostoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCosti extends ListRecords
{
    protected static string $resource = CostoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
