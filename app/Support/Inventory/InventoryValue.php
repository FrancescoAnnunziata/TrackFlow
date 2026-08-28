<?php

namespace App\Support\Inventory;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Normalizza i valori grezzi del CSV di censimento endpoint.
 *
 * Lo script PowerShell scrive quasi tutto come stringa: "SI"/"NO" (a volte con
 * un suffisso esplicativo, es. "NO - disabilitato"), "N/D" e "N/D (serve admin)"
 * per cio' che non ha potuto leggere, numeri con la virgola decimale italiana e
 * date in formato ISO. Qui i valori diventano tipi PHP, mantenendo la
 * distinzione fra "no" e "non rilevato" (null).
 */
class InventoryValue
{
    /** Testo ripulito: null se vuoto o se e' uno dei marcatori "non rilevato". */
    public static function text(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            return null;
        }

        $nulls = (array) config('inventario_endpoint.csv.null_values', []);

        if (in_array(mb_strtolower($trimmed), $nulls, true)) {
            return null;
        }

        // "N/D (serve admin)" e varianti: il dettaglio fra parentesi cambia,
        // ma resta un valore non rilevato.
        if (Str::startsWith(mb_strtolower($trimmed), ['n/d', 'n/a'])) {
            return null;
        }

        return $trimmed;
    }

    /**
     * SI/NO tri-stato. Il confronto e' sul prefisso perche' lo script allega
     * spesso la motivazione: "NO (workgroup)", "NO - password admin locale non
     * gestita", "SI (Azure AD)".
     */
    public static function bool(?string $value): ?bool
    {
        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        $normalized = mb_strtolower($text);

        if (Str::startsWith($normalized, ['si', 'sì', 'yes', 'true', 'on', 'abilitato', 'attivo', 'enabled', '1'])) {
            return true;
        }

        if (Str::startsWith($normalized, ['no', 'false', 'off', 'disabilitato', 'disattivato', 'disabled', '0'])) {
            return false;
        }

        return null;
    }

    public static function int(?string $value): ?int
    {
        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        // Tiene solo cifre e segno: scarta unita' di misura eventuali ("7 giorni").
        $digits = preg_replace('/[^0-9\-]/', '', $text);

        return $digits === '' || $digits === '-' ? null : (int) $digits;
    }

    /** Decimale con virgola italiana ("15,9") o punto ("15.9"). */
    public static function decimal(?string $value): ?float
    {
        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        $normalized = str_replace(',', '.', preg_replace('/[^0-9,.\-]/', '', $text));

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    /** Data (o data/ora) nei formati emessi dallo script; null se non parsabile. */
    public static function date(?string $value): ?Carbon
    {
        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        foreach ((array) config('inventario_endpoint.csv.date_formats', []) as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $text);
            } catch (Throwable) {
                continue;
            }

            if ($parsed === false) {
                continue;
            }

            // createFromFormat completa con l'ora corrente le parti mancanti:
            // per i formati di sola data l'orario e' rumore, non un dato.
            return Str::contains($format, ['H', 'G', 'h', 'g']) ? $parsed : $parsed->startOfDay();
        }

        try {
            return Carbon::parse($text);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Liste separate da ";" dentro una singola cella (membri di gruppo, account
     * locali, software). Ritorna gli elementi ripuliti, senza vuoti.
     *
     * @return array<int, string>
     */
    public static function list(?string $value): array
    {
        $text = self::text($value);

        if ($text === null) {
            return [];
        }

        return collect(explode(';', $text))
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Nome account senza prefisso macchina/dominio: "DESKTOP-X\franc" -> "franc".
     */
    public static function accountName(string $value): string
    {
        return trim(Str::afterLast(trim($value), '\\'));
    }
}
