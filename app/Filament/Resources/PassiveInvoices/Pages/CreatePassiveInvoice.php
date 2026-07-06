<?php

namespace App\Filament\Resources\PassiveInvoices\Pages;

use App\Filament\Resources\PassiveInvoices\PassiveInvoiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePassiveInvoice extends CreateRecord
{
    protected static string $resource = PassiveInvoiceResource::class;
}
