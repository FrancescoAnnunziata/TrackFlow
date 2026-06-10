<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('ficPayload')
                ->label('Payload Fatture in Cloud')
                ->icon(Heroicon::OutlinedCodeBracket)
                ->color('gray')
                ->modalHeading('Payload Fatture in Cloud')
                ->modalDescription('Body JSON pronto per POST /c/{company_id}/issued_documents.')
                ->modalContent(fn (Invoice $record): HtmlString => new HtmlString(
                    '<pre style="background:#0f172a;color:#e2e8f0;padding:1rem;border-radius:0.5rem;overflow:auto;max-height:60vh;font-size:0.75rem;line-height:1.4;"><code>'
                    . e(json_encode($record->toFicPayload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                    . '</code></pre>'
                ))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Chiudi'),
        ];
    }
}
