<?php

namespace App\Filament\Resources\SecurityFindings\Pages;

use App\Filament\Resources\SecurityFindings\SecurityFindingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSecurityFinding extends EditRecord
{
    protected static string $resource = SecurityFindingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
