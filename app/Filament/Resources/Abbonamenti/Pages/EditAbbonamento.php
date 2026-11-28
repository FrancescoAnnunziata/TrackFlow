<?php

namespace App\Filament\Resources\Abbonamenti\Pages;

use App\Filament\Resources\Abbonamenti\AbbonamentoResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAbbonamento extends EditRecord
{
    protected static string $resource = AbbonamentoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
