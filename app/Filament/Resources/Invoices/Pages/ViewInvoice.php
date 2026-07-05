<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Hours\HourResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\FicCredential;
use App\Models\Invoice;
use App\Services\Fic\FicClient;
use App\Services\Fic\FicException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewHours')
                ->label('Vedi ore fatturate')
                ->icon(Heroicon::OutlinedClock)
                ->color('primary')
                ->url(fn (Invoice $record): string => HourResource::getUrl('index', [
                    'tableFilters' => [
                        'invoice' => ['value' => $record->getKey()],
                    ],
                ])),
            EditAction::make(),
            $this->sendToFicAction(),
            Action::make('ficPayload')
                ->label('Payload Fatture in Cloud')
                ->icon(Heroicon::OutlinedCodeBracket)
                ->color('gray')
                ->modalHeading('Payload Fatture in Cloud')
                ->modalDescription('Body JSON pronto per POST /c/{company_id}/issued_documents.')
                ->modalContent(fn (Invoice $record): HtmlString => new HtmlString(
                    '<pre style="background:#0f172a;color:#e2e8f0;padding:1rem;border-radius:0.5rem;overflow:auto;max-height:60vh;font-size:0.75rem;line-height:1.4;"><code>'
                    .e(json_encode($record->toFicPayload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                    .'</code></pre>'
                ))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Chiudi'),
        ];
    }

    /**
     * Crea la fattura su Fatture in Cloud (documento registrato, non SDI).
     */
    private function sendToFicAction(): Action
    {
        return Action::make('sendToFic')
            ->label(fn (Invoice $record): string => $record->isSentToFic() ? 'Già su Fatture in Cloud' : 'Invia a Fatture in Cloud')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->visible(fn (Invoice $record): bool => FicCredential::isConnected() && ($record->client?->isBillableHere() ?? false))
            ->disabled(fn (Invoice $record): bool => $record->isSentToFic())
            ->requiresConfirmation()
            ->modalDescription('Crea la fattura elettronica su Fatture in Cloud. La trasmissione al SDI la fai/verifichi dal pannello FIC (a seconda delle tue impostazioni di invio automatico).')
            ->action(function (Invoice $record): void {
                $client = $record->client;

                // Serve almeno P.IVA o codice fiscale per l'anagrafica FIC.
                if (blank($client?->vat_number) && blank($client?->tax_code)) {
                    Notification::make()
                        ->danger()
                        ->title('Dati fiscali mancanti')
                        ->body('Il cliente non ha né P.IVA né codice fiscale. Completali nell\'anagrafica prima di inviare la fattura.')
                        ->send();

                    return;
                }

                try {
                    $document = FicClient::fromConfig()->createIssuedDocument($record);
                } catch (FicException $e) {
                    Notification::make()
                        ->danger()
                        ->title('Invio non riuscito')
                        ->body($e->getMessage())
                        ->send();

                    return;
                }

                $record->update([
                    // La numerazione è decisa da FIC: eredita il numero assegnato.
                    'number' => Invoice::formatFicNumber($document) ?? $record->number,
                    'fic_document_id' => $document['id'] ?? null,
                    'fic_document_token' => $document['token'] ?? null,
                    'fic_sent_at' => now(),
                    'status' => $record->status === 'draft' ? 'sent' : $record->status,
                ]);

                Notification::make()
                    ->success()
                    ->title('Fattura creata su Fatture in Cloud')
                    ->body("Numero assegnato da FIC: {$record->number}. Fattura elettronica pronta — trasmettila/verificala al SDI dal pannello FIC.")
                    ->send();
            });
    }
}
