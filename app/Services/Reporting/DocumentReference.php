<?php

namespace App\Services\Reporting;

use App\Models\Costo;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Reimbursement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Riferimento al giustificativo di un documento, usato in modo uniforme da tutte
 * le esportazioni contabili (prima nota, registro acquisti, riconciliazioni):
 *
 *  - se il documento ha un PDF/foto caricato in TrackFlow → URL pubblico
 *    (disco public, apribile da chiunque abbia il link, anche il commercialista);
 *  - se il PDF è su Fatture in Cloud (fatture importate) → testo esplicativo
 *    "Vedere fattura N su Fatture in Cloud" (FiC non espone un link stabile e
 *    pubblico);
 *  - altrimenti (es. costo senza giustificativo) → stringa vuota (nessun link).
 */
class DocumentReference
{
    /**
     * Cella della colonna "Giustificativo/Link documento": URL pubblico, testo
     * "Vedere fattura … su Fatture in Cloud", oppure stringa vuota.
     */
    public static function linkCell(?Model $doc): string
    {
        $url = self::attachmentUrl($doc);
        if ($url !== null) {
            return $url;
        }

        if (self::isOnFic($doc)) {
            return 'Vedere fattura '.self::number($doc).' su Fatture in Cloud';
        }

        return '';
    }

    /**
     * URL pubblico del PDF/foto allegato al documento sul disco public, se c'è.
     * Preferisce un PDF; in mancanza prende il primo allegato.
     */
    public static function attachmentUrl(?Model $doc): ?string
    {
        $paths = match (true) {
            $doc instanceof PassiveInvoice => [$doc->attachment],
            $doc instanceof Costo, $doc instanceof Reimbursement => $doc->attachments ?? [],
            $doc instanceof Expense => $doc->attachaments ?? [],
            default => [],
        };

        $paths = array_values(array_filter($paths, fn ($p): bool => is_string($p) && $p !== ''));

        if ($paths === []) {
            return null;
        }

        foreach ($paths as $path) {
            if (str_ends_with(strtolower($path), '.pdf')) {
                return Storage::disk('public')->url($path);
            }
        }

        return Storage::disk('public')->url($paths[0]);
    }

    /**
     * True se è una fattura passiva estera: caricata a mano con PDF e senza
     * corrispettivo su Fatture in Cloud (le domestiche arrivano da FiC).
     */
    public static function isForeignPassive(?Model $doc): bool
    {
        return $doc instanceof PassiveInvoice
            && ! $doc->isCreditNote()
            && filled($doc->attachment)
            && blank($doc->fic_document_id);
    }

    private static function isOnFic(?Model $doc): bool
    {
        return ($doc instanceof Invoice || $doc instanceof PassiveInvoice) && filled($doc->fic_document_id);
    }

    private static function number(?Model $doc): string
    {
        return (string) ($doc->number ?? '');
    }
}
