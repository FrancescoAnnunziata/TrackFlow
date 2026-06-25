<?php

namespace App\Filament\Resources\SecurityFindings\Pages;

use App\Filament\Resources\SecurityFindings\SecurityFindingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSecurityFindings extends ListRecords
{
    protected static string $resource = SecurityFindingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
