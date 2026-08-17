<?php

namespace App\Services\Billing;

use App\Models\Invoice;

/**
 * Esito di un'importazione: oltre alla fattura serve sapere se è stata creata
 * ora o ritrovata (per rispondere 201 o 200) e cosa vale la pena segnalare a
 * chi integra senza per questo rifiutare il documento.
 */
class SubscriptionInvoiceResult
{
    /**
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public readonly Invoice $invoice,
        public readonly bool $created,
        public readonly bool $clientCreated,
        public readonly array $warnings = [],
    ) {}
}
