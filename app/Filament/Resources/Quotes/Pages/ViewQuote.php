<?php

namespace App\Filament\Resources\Quotes\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Models\Invoice;
use App\Models\Quote;
use App\Notifications\QuoteDecidedNotification;
use App\Notifications\QuoteSubmittedNotification;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Notification;

class ViewQuote extends ViewRecord
{
    protected static string $resource = QuoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->sendAction(),
            $this->resendAction(),
            $this->acceptAction(),
            $this->rejectAction(),
            $this->generateInvoiceAction(),
            EditAction::make()
                ->visible(fn (Quote $record): bool => auth()->user()->isAdmin()),
            DeleteAction::make()
                ->visible(fn (Quote $record): bool => auth()->user()->isAdmin()),
        ];
    }

    /**
     * Admin: invia il preventivo ai referenti del cliente con magic link.
     */
    private function sendAction(): Action
    {
        return Action::make('send')
            ->label('Invia al cliente')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->visible(fn (Quote $record): bool => auth()->user()->isAdmin() && $record->status === Quote::STATUS_DRAFT)
            ->requiresConfirmation()
            ->modalDescription('Invia il preventivo via email a tutti i referenti del cliente, con un link di accesso per approvarlo.')
            ->action(fn (Quote $record) => $this->dispatchToClient($record));
    }

    /**
     * Admin: reinvia un preventivo già inviato (nuovo link, conteggio solleciti azzerato).
     */
    private function resendAction(): Action
    {
        return Action::make('resend')
            ->label('Reinvia al cliente')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (Quote $record): bool => auth()->user()->isAdmin() && $record->status === Quote::STATUS_SENT)
            ->requiresConfirmation()
            ->modalDescription('Reinvia il preventivo ai referenti del cliente con un nuovo link di accesso. La data di invio e il conteggio dei solleciti ripartono da zero.')
            ->action(fn (Quote $record) => $this->dispatchToClient($record, resend: true));
    }

    /**
     * Invia (o reinvia) il preventivo: magic link ai referenti, copia per
     * conoscenza all'emittente, e (re)imposta stato/data invio/solleciti.
     */
    private function dispatchToClient(Quote $record, bool $resend = false): void
    {
        $recipients = $record->client->contacts;

        if ($recipients->isEmpty()) {
            FilamentNotification::make()
                ->warning()
                ->title('Nessun referente')
                ->body('Il cliente non ha utenti referente a cui inviare il preventivo. Creane uno dalla sezione Utenti.')
                ->send();

            return;
        }

        $record->update([
            'status' => Quote::STATUS_SENT,
            'sent_at' => now(),
            'reminders_sent' => 0,
        ]);

        Notification::send($recipients, new QuoteSubmittedNotification($record));
        Notification::send($record->user, new QuoteSubmittedNotification($record));

        FilamentNotification::make()
            ->success()
            ->title($resend ? 'Preventivo reinviato' : 'Preventivo inviato')
            ->body('Email inviata a '.$recipients->count().' referente/i.')
            ->send();
    }

    /**
     * Cliente: accetta il preventivo.
     */
    private function acceptAction(): Action
    {
        return Action::make('accept')
            ->label('Accetta')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Quote $record): bool => auth()->user()->isClient() && $record->status === Quote::STATUS_SENT)
            ->requiresConfirmation()
            ->modalDescription('Confermi di accettare questo preventivo?')
            ->action(fn (Quote $record) => $this->decide($record, Quote::STATUS_ACCEPTED));
    }

    /**
     * Cliente: rifiuta il preventivo.
     */
    private function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Rifiuta')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Quote $record): bool => auth()->user()->isClient() && $record->status === Quote::STATUS_SENT)
            ->requiresConfirmation()
            ->modalDescription('Confermi di rifiutare questo preventivo?')
            ->action(fn (Quote $record) => $this->decide($record, Quote::STATUS_REJECTED));
    }

    /**
     * Applica la decisione del cliente e notifica admin + cliente.
     */
    private function decide(Quote $record, string $status): void
    {
        $accepted = $status === Quote::STATUS_ACCEPTED;

        $record->update([
            'status' => $status,
            'accepted_at' => $accepted ? now() : null,
            'accepted_by' => $accepted ? auth()->id() : null,
        ]);

        $decidedBy = auth()->user();

        // All'emittente (admin, link al pannello) e ai referenti (magic link).
        Notification::send($record->user, new QuoteDecidedNotification($record, $decidedBy));
        Notification::send($record->client->contacts, new QuoteDecidedNotification($record, $decidedBy));

        FilamentNotification::make()
            ->color($accepted ? 'success' : 'danger')
            ->title($accepted ? 'Preventivo accettato' : 'Preventivo rifiutato')
            ->send();
    }

    /**
     * Admin: genera una fattura in bozza dal preventivo accettato.
     */
    private function generateInvoiceAction(): Action
    {
        return Action::make('generateInvoice')
            ->label('Genera fattura')
            ->icon(Heroicon::OutlinedDocumentText)
            ->color('primary')
            ->visible(fn (Quote $record): bool => auth()->user()->isAdmin()
                && $record->status === Quote::STATUS_ACCEPTED
                && $record->invoice_id === null)
            ->requiresConfirmation()
            ->modalDescription('Crea una fattura in bozza con stesso cliente, tariffa e IVA. Le ore effettive le aggancerai poi alla fattura.')
            ->action(function (Quote $record) {
                $invoice = Invoice::create([
                    'user_id' => auth()->id(),
                    'client_id' => $record->client_id,
                    // Numero assegnato da FIC all'invio (non inventato qui).
                    'issue_date' => now(),
                    'period_from' => now()->startOfMonth(),
                    'period_to' => now()->endOfMonth(),
                    'hourly_rate' => $record->hourly_rate,
                    'vat_rate' => $record->vat_rate,
                    'status' => 'draft',
                    'notes' => trim("Da preventivo {$record->number}\n".(string) $record->description),
                ]);

                $record->update([
                    'status' => Quote::STATUS_INVOICED,
                    'invoice_id' => $invoice->getKey(),
                ]);

                FilamentNotification::make()
                    ->success()
                    ->title('Fattura creata')
                    ->body("Fattura {$invoice->number} generata in bozza.")
                    ->send();

                return redirect(InvoiceResource::getUrl('edit', ['record' => $invoice]));
            });
    }
}
