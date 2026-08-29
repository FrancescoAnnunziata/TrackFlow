<?php

namespace App\Support;

use App\Models\Client;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Le finestre temporali di una fattura, calcolate dalla configurazione del
 * cliente: quale periodo si fattura, quale si conguaglia, e — soprattutto — da
 * quale periodo si prendono le spese da riaddebitare.
 *
 * Esiste perché quelle tre finestre non coincidono sempre. Sui clienti
 * fatturati in anticipo con conguaglio (es. Alsea) si fattura il trimestre che
 * viene, ma ore e spese arrivano dal trimestre appena chiuso: è una fonte di
 * errori se non la si vede scritta. Il motore di fatturazione e il riepilogo
 * mostrato in fase di generazione leggono da qui, così non possono divergere.
 */
final class PeriodoFatturazione
{
    private function __construct(
        /** Primo giorno del periodo fatturato. */
        public readonly CarbonImmutable $da,
        /** Ultimo giorno del periodo fatturato. */
        public readonly CarbonImmutable $a,
        /** Periodo precedente da conguagliare, se previsto dal cliente. */
        public readonly ?CarbonImmutable $conguaglioDa,
        public readonly ?CarbonImmutable $conguaglioA,
        /** Finestra da cui si pescano le spese non ancora fatturate. */
        public readonly CarbonImmutable $speseDa,
        public readonly CarbonImmutable $speseA,
    ) {}

    public static function per(Client $client, CarbonInterface $inizio): self
    {
        $mesi = max(1, (int) $client->billing_period_months);
        $inizio = CarbonImmutable::instance($inizio)->startOfMonth();

        [$da, $a] = self::finestra($inizio, $mesi);

        if (! self::conguaglia($client)) {
            return new self($da, $a, null, null, $da, $a);
        }

        [$pDa, $pA] = self::finestra($inizio->subMonths($mesi), $mesi);

        // Le spese seguono il periodo conguagliato: sono costi già sostenuti,
        // appartengono al periodo chiuso e non a quello che deve ancora iniziare.
        return new self($da, $a, $pDa, $pA, $pDa, $pA);
    }

    /**
     * True se al periodo fatturato si somma il conguaglio del precedente. Vale
     * solo per i clienti a ore fatturati in anticipo che l'hanno configurato:
     * forfait e a giornata non conguagliano.
     */
    public static function conguaglia(Client $client): bool
    {
        return $client->billing_timing === Client::TIMING_ADVANCE
            && $client->billing_model !== Client::MODEL_FORFAIT
            && $client->billing_model !== Client::MODEL_DAILY
            && (bool) $client->reconcile_previous_period;
    }

    /** True se le spese arrivano da un periodo diverso da quello fatturato. */
    public function speseSfasate(): bool
    {
        return ! $this->speseDa->equalTo($this->da);
    }

    public function etichettaPeriodo(): string
    {
        return self::etichetta($this->da, $this->a);
    }

    public function etichettaSpese(): string
    {
        return self::etichetta($this->speseDa, $this->speseA);
    }

    public static function etichetta(CarbonImmutable $da, CarbonImmutable $a): string
    {
        return $da->translatedFormat('m/Y').' – '.$a->translatedFormat('m/Y');
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function finestra(CarbonImmutable $inizio, int $mesi): array
    {
        return [$inizio->startOfMonth(), $inizio->addMonths($mesi - 1)->endOfMonth()];
    }
}
