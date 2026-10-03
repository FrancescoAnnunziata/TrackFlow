<?php

namespace App\Assistant;

use App\Filament\Resources\BankTransactions\BankTransactionResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\PassiveInvoices\PassiveInvoiceResource;
use App\Filament\Resources\Reimbursements\ReimbursementResource;
use Throwable;

/**
 * Link alla scheda di un documento in TrackFlow, da mettere nelle risposte
 * dell'assistente accanto all'id.
 *
 * Il link lo costruisce il server e lo consegna dentro il risultato dello
 * strumento: il modello lo copia, non lo compone. È la stessa ragione per cui
 * le proposte si costruiscono da id validati — un URL "ragionevole" inventato
 * da un modello è indistinguibile da uno vero finché non ci si clicca sopra.
 *
 * Non tutte le risorse hanno la pagina di sola lettura: i movimenti bancari si
 * aprono in modifica, perché è lì che si riconciliano.
 */
class LinkDocumento
{
    public static function movimento(int $id): string
    {
        return self::costruisci(BankTransactionResource::class, 'edit', $id);
    }

    public static function fatturaPassiva(int $id): string
    {
        return self::costruisci(PassiveInvoiceResource::class, 'view', $id);
    }

    public static function fatturaAttiva(int $id): string
    {
        return self::costruisci(InvoiceResource::class, 'view', $id);
    }

    public static function rimborso(int $id): string
    {
        return self::costruisci(ReimbursementResource::class, 'view', $id);
    }

    public static function spesa(int $id): string
    {
        return self::costruisci(ExpenseResource::class, 'view', $id);
    }

    /**
     * @param  class-string  $resource
     */
    private static function costruisci(string $resource, string $pagina, int $id): string
    {
        try {
            return $resource::getUrl($pagina, ['record' => $id]);
        } catch (Throwable) {
            // Fuori da un pannello Filament (e il turno gira in coda) getUrl
            // solleva. Meglio una riga senza link che un turno fallito: il job
            // imposta il pannello, questo è solo l'ultimo paracadute.
            return '';
        }
    }
}
