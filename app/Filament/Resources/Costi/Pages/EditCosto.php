<?php

namespace App\Filament\Resources\Costi\Pages;

use App\Filament\Resources\Costi\CostoResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCosto extends EditRecord
{
    protected static string $resource = CostoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
