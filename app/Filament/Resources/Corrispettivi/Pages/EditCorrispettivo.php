<?php

namespace App\Filament\Resources\Corrispettivi\Pages;

use App\Filament\Resources\Corrispettivi\CorrispettivoResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCorrispettivo extends EditRecord
{
    protected static string $resource = CorrispettivoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
