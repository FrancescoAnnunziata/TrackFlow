<?php

namespace App\Services\Fic;

use App\Models\PassiveInvoice;
use Illuminate\Support\Collection;

/**
 * Collega le integrazioni dell'esterometro alla fattura estera che integrano.
 *
 * Funziona nei due ordini in cui le cose possono succedere:
 *  - la fattura estera c'è già (il caso normale: Paola la carica, poi il
 *    commercialista emette l'integrazione) → link() all'import;
 *  - l'integrazione arriva prima che la fattura estera sia caricata → resta in
 *    attesa col riferimento letto dall'XML, e linkPendingTo() la aggancia
 *    quando la fattura estera viene creata.
 */
class IntegrationLinker
{
    /**
     * Trova la fattura estera indicata dall'integrazione e la collega. Non
     * indovina: se il numero corrisponde a più fatture e la data non scioglie
     * il dubbio, l'integrazione resta da collegare.
     */
    public function link(PassiveInvoice $integration): ?PassiveInvoice
    {
        if (blank($integration->integrates_number)) {
            return null;
        }

        $date = $integration->integrates_date?->toDateString();
        $wanted = trim($integration->integrates_number);

        $candidates = $this->originals()
            ->whereRaw('LOWER(TRIM(number)) = ?', [mb_strtolower($wanted)])
            ->get();

        // Il commercialista può aver scritto solo la parte numerica («70743» per
        // «MEILI-70743»). Qui il numero da solo è debole, quindi deve tornare
        // anche la data della fattura.
        if ($candidates->isEmpty() && $date !== null && ($digits = self::digits($wanted)) !== '') {
            $candidates = $this->originals()
                ->where('number', 'like', '%'.$digits)
                ->whereDate('document_date', $date)
                ->get()
                ->filter(fn (PassiveInvoice $p): bool => self::digits($p->number) === $digits);
        }

        $original = $this->pick($candidates, $date);

        if ($original !== null) {
            $integration->forceFill([
                'integrates_id' => $original->id,
                // Il fornitore giusto è quello della fattura vera: FiC scrive la
                // P.IVA estera senza prefisso e il nome in maiuscolo.
                'supplier_id' => $original->supplier_id,
            ])->save();
        }

        return $original;
    }

    /**
     * Aggancia a una fattura estera appena creata le integrazioni che la
     * aspettavano.
     */
    public function linkPendingTo(PassiveInvoice $original): int
    {
        if (blank($original->number) || filled($original->fic_document_id)) {
            return 0;
        }

        $linked = 0;
        $digits = self::digits($original->number);
        $pending = PassiveInvoice::conIntegrazioni()
            ->whereNotNull('integration_type')
            ->whereNull('integrates_id')
            ->whereNotNull('integrates_number')
            ->get()
            ->filter(fn (PassiveInvoice $i): bool => mb_strtolower(trim($i->integrates_number)) === mb_strtolower(trim($original->number))
                || ($digits !== '' && self::digits($i->integrates_number) === $digits));

        foreach ($pending as $integration) {
            if ($this->link($integration)?->is($original)) {
                $linked++;
            }
        }

        return $linked;
    }

    private static function digits(?string $value): string
    {
        return preg_replace('/\D/', '', (string) $value) ?? '';
    }

    /** Le fatture che un'integrazione può integrare: estere, caricate a mano. */
    private function originals()
    {
        return PassiveInvoice::query()->whereNull('fic_document_id');
    }

    /**
     * @param  Collection<int, PassiveInvoice>  $candidates
     */
    private function pick(Collection $candidates, ?string $date): ?PassiveInvoice
    {
        if ($candidates->count() > 1 && $date !== null) {
            $candidates = $candidates->filter(fn (PassiveInvoice $p): bool => $p->document_date?->toDateString() === $date);
        }

        return $candidates->count() === 1 ? $candidates->first() : null;
    }
}
