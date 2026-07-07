<?php

namespace App\Filament\Resources\Reimbursements\Pages;

use App\Filament\Resources\Reimbursements\ReimbursementResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewReimbursement extends ViewRecord
{
    protected static string $resource = ReimbursementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
