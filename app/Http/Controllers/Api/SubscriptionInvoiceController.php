<?php

namespace App\Http\Controllers\Api;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SubscriptionInvoiceRequest;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\SubscriptionInvoiceDraftedNotification;
use App\Services\Billing\SubscriptionInvoiceException;
use App\Services\Billing\SubscriptionInvoiceImporter;
use App\Services\Billing\SubscriptionInvoiceResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Riceve un abbonamento incassato e ne fa una fattura in bozza.
 *
 * L'endpoint si ferma qui di proposito: l'invio a Fatture in Cloud e al SDI
 * resta un gesto manuale dal pannello, perché da lì in poi l'unico modo di
 * correggere un errore è una nota di credito.
 *
 * Contratto completo: docs/api-abbonamenti.md
 */
class SubscriptionInvoiceController extends Controller
{
    public function __invoke(SubscriptionInvoiceRequest $request, SubscriptionInvoiceImporter $importer): JsonResponse
    {
        try {
            $result = $importer->import($request->validated());
        } catch (SubscriptionInvoiceException $e) {
            return response()->json([
                'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()],
            ], $e->status);
        }

        if ($result->created) {
            $this->notifyAdmins($result->invoice);
        }

        // 201 solo la prima volta: i retry del chiamante devono vedere un esito
        // di successo, non un errore che finirebbe nella sua coda dei falliti.
        return response()->json($this->body($result), $result->created ? 201 : 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(SubscriptionInvoiceResult $result): array
    {
        $invoice = $result->invoice;

        return [
            'created' => $result->created,
            'invoice' => [
                'id' => $invoice->getKey(),
                // Assegnato da Fatture in Cloud all'invio: prima è null.
                'number' => $invoice->number,
                'status' => $invoice->status,
                'issue_date' => $invoice->issue_date?->toDateString(),
                'taxable_amount' => $invoice->taxableAmount(),
                'vat_amount' => $invoice->vatAmount(),
                'total' => $invoice->total(),
                'sent_to_fic' => $invoice->isSentToFic(),
                'panel_url' => self::panelUrl($invoice),
            ],
            'client' => [
                'id' => $invoice->client?->getKey(),
                'name' => $invoice->client?->name,
                'created' => $result->clientCreated,
            ],
            'warnings' => $result->warnings,
        ];
    }

    /**
     * Link alla fattura nel pannello, da mettere nei log di chi integra.
     */
    public static function panelUrl(Invoice $invoice): ?string
    {
        try {
            return InvoiceResource::getUrl('view', ['record' => $invoice->getKey()]);
        } catch (\Throwable) {
            // Fuori da una richiesta del pannello la risoluzione può fallire:
            // è un comodo in più, non un motivo per rifiutare la fattura.
            return null;
        }
    }

    /**
     * Avvisa che c'è una bozza da controllare e spedire. Un intoppo dell'invio
     * email non deve far fallire una fattura già registrata: il chiamante
     * riproverebbe, e questa volta senza motivo.
     */
    private function notifyAdmins(Invoice $invoice): void
    {
        try {
            $admins = User::query()->where('role', 'admin')->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new SubscriptionInvoiceDraftedNotification($invoice));
            }
        } catch (\Throwable $e) {
            Log::error('Notifica della fattura da abbonamento non inviata: '.$e->getMessage());
        }
    }
}
