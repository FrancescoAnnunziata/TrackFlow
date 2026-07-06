<?php

namespace App\Filament\Resources\PassiveInvoices\Pages;

use App\Filament\Resources\PassiveInvoices\PassiveInvoiceResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPassiveInvoice extends ViewRecord
{
    protected static string $resource = PassiveInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
