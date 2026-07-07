<?php

namespace App\Filament\Resources\Reimbursements\Pages;

use App\Filament\Resources\Reimbursements\ReimbursementResource;
use Filament\Resources\Pages\CreateRecord;

class CreateReimbursement extends CreateRecord
{
    protected static string $resource = ReimbursementResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Il rimborso appartiene sempre all'utente che lo registra.
        $data['user_id'] = auth()->id();

        return $data;
    }
}
