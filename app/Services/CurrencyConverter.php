<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Converte un importo in valuta estera in EUR usando i cambi di riferimento BCE
 * (frankfurter.app, gratuito e senza chiave). Le fatture estere si registrano in
 * EUR per riconciliarle col movimento bancario; l'originale in valuta resta come
 * riferimento. Il cambio usato è quello della data documento (o giorno
 * lavorativo più vicino), come da prassi contabile.
 */
class CurrencyConverter
{
    private const BASE_URL = 'https://api.frankfurter.app';

    /**
     * Converte $amount da $currency a EUR al cambio della data $date (YYYY-MM-DD).
     * Ritorna null se la valuta è già EUR, non serve conversione, o il cambio non
     * è recuperabile (così il chiamante può chiedere l'importo a mano).
     */
    public function toEur(float $amount, string $currency, ?string $date = null): ?float
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '' || $currency === 'EUR' || $amount == 0.0) {
            return null;
        }

        $rate = $this->rate($currency, $date);

        return $rate === null ? null : round($amount * $rate, 2);
    }

    /**
     * Cambio 1 unità di $currency in EUR alla data data (cache 30 giorni: i cambi
     * storici non cambiano).
     */
    public function rate(string $currency, ?string $date = null): ?float
    {
        $currency = strtoupper(trim($currency));
        $day = $date !== null ? substr($date, 0, 10) : 'latest';
        $cacheKey = "fx:{$currency}:EUR:{$day}";

        return Cache::remember($cacheKey, now()->addDays(30), function () use ($currency, $day): ?float {
            try {
                $response = Http::timeout(8)->get(self::BASE_URL.'/'.$day, [
                    'from' => $currency,
                    'to' => 'EUR',
                ]);

                if ($response->failed()) {
                    return null;
                }

                $eur = $response->json('rates.EUR');

                return is_numeric($eur) ? (float) $eur : null;
            } catch (Throwable) {
                return null;
            }
        });
    }
}
