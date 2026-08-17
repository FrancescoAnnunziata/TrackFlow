<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use RuntimeException;

/**
 * Un rifiuto che il chiamante deve poter distinguere dagli altri: `code` è
 * pensato per essere confrontato dal codice di chi integra, `message` per
 * essere letto da una persona.
 */
class SubscriptionInvoiceException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    /**
     * La fattura per questo pagamento è già partita verso Fatture in Cloud:
     * da lì in poi è un documento fiscale, si corregge solo con una nota di
     * credito emessa a mano.
     */
    public static function alreadySent(Invoice $invoice): self
    {
        return new self(
            'invoice_already_sent',
            sprintf(
                'La fattura per questo pagamento (id %d, numero %s) è già stata inviata a Fatture in Cloud e non è più modificabile.',
                $invoice->getKey(),
                $invoice->number ?? 'non assegnato',
            ),
            409,
        );
    }

    /**
     * Nessun utente a cui intestare la fattura: TrackFlow è appena installato
     * o il database è vuoto.
     */
    public static function noOwner(): self
    {
        return new self(
            'no_invoice_owner',
            'Nessun utente disponibile a cui intestare la fattura su TrackFlow.',
            503,
        );
    }
}
