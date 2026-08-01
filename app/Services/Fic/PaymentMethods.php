<?php

namespace App\Services\Fic;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Opzioni per i due campi "metodo di pagamento" della scheda cliente.
 *
 * Sono due cose diverse e vanno tenute distinte:
 *  - ModalitaPagamento SDI (MP01, MP05, ...): finisce nell'XML della fattura
 *    elettronica ed è obbligatoria. Lista fissa, definita dall'Agenzia Entrate.
 *  - Metodo di pagamento FIC: è l'anagrafica dell'azienda su Fatture in Cloud
 *    (con IBAN e istruzioni stampate sulla fattura). Gli id sono per-azienda,
 *    quindi si leggono da FIC invece di farli digitare a mano.
 */
class PaymentMethods
{
    /** Quanto teniamo in cache la lista letta da FIC (cambia di rado). */
    private const CACHE_TTL_MINUTES = 30;

    private const CACHE_KEY = 'fic.payment_methods';

    /**
     * ModalitaPagamento SDI più usate. Non è l'elenco completo dell'Agenzia
     * Entrate: sono quelle che servono qui, il resto si aggiunge se capita.
     *
     * @return array<string, string>
     */
    public static function sdiOptions(): array
    {
        return [
            'MP01' => 'MP01 — Contanti',
            'MP02' => 'MP02 — Assegno',
            'MP05' => 'MP05 — Bonifico',
            'MP08' => 'MP08 — Carta di pagamento',
            'MP12' => 'MP12 — RIBA',
            'MP19' => 'MP19 — SEPA Direct Debit',
            'MP21' => 'MP21 — SEPA Direct Debit CORE',
            'MP23' => 'MP23 — PagoPA',
        ];
    }

    /**
     * Metodi di pagamento dell'azienda su FIC, come id => nome.
     *
     * Rete lenta o FIC non collegato non devono rompere la scheda cliente: in
     * caso di errore ritorna lista vuota (il form lo dice con un helper text).
     *
     * @return array<int, string>
     */
    public static function ficOptions(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $info = FicClient::fromConfig()->info();
        } catch (Throwable) {
            // Niente cache sui fallimenti: al prossimo giro si riprova, così una
            // connessione momentaneamente giù non svuota la tendina per mezz'ora.
            return [];
        }

        $options = collect($info['payment_methods'] ?? [])
            ->filter(fn ($method): bool => filled($method['id'] ?? null))
            ->mapWithKeys(fn (array $method): array => [
                (int) $method['id'] => (string) ($method['name'] ?? ('#'.$method['id'])),
            ])
            ->all();

        Cache::put(self::CACHE_KEY, $options, now()->addMinutes(self::CACHE_TTL_MINUTES));

        return $options;
    }

    /**
     * Svuota la cache: serve dopo un (ri)collegamento a Fatture in Cloud, che
     * può cambiare azienda e quindi l'elenco.
     */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
