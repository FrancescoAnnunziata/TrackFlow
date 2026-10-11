<?php

namespace App\Assistant;

use App\Filament\Resources\BankTransactions\BankTransactionResource;
use App\Filament\Resources\Costi\CostoResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\PassiveInvoices\PassiveInvoiceResource;
use App\Filament\Resources\Reimbursements\ReimbursementResource;
use App\Models\Costo;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Reimbursement;
use Illuminate\Database\Eloquent\Model;
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

    public static function costo(int $id): string
    {
        // I costi non hanno la pagina di sola lettura.
        return self::costruisci(CostoResource::class, 'edit', $id);
    }

    /**
     * Link al documento qualunque sia il suo tipo (es. quello a cui è
     * riconciliato un movimento). Stringa vuota per un tipo che non conosce.
     */
    public static function documento(?Model $doc): string
    {
        return match (true) {
            $doc instanceof Invoice => self::fatturaAttiva($doc->id),
            $doc instanceof PassiveInvoice => self::fatturaPassiva($doc->id),
            $doc instanceof Costo => self::costo($doc->id),
            $doc instanceof Reimbursement => self::rimborso($doc->id),
            $doc instanceof Expense => self::spesa($doc->id),
            default => '',
        };
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
