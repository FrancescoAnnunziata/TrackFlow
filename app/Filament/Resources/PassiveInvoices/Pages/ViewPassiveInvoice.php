<?php

namespace App\Filament\Resources\PassiveInvoices\Pages;

use App\Filament\Concerns\InteractsWithDocumentReconciliation;
use App\Filament\Resources\PassiveInvoices\PassiveInvoiceResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPassiveInvoice extends ViewRecord
{
    use InteractsWithDocumentReconciliation;

    protected static string $resource = PassiveInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->viewDocumentReconciliationsAction(),
            $this->reconcileDocumentAction(),
            EditAction::make(),
        ];
    }
}
