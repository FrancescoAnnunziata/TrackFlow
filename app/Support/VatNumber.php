<?php

namespace App\Support;

/**
 * Normalizzazione delle partite IVA italiane.
 *
 * In anagrafica lo stesso numero può stare scritto in tre modi ("IT01234567890",
 * "01234567890", "IT 01234567890"): senza una forma canonica il find-or-create
 * dell'API creerebbe un cliente doppione per un cliente che esiste già.
 */
class VatNumber
{
    /**
     * Forma canonica: solo le 11 cifre, senza prefisso paese né separatori.
     * Se il valore non ha la forma di una P.IVA italiana torna indietro
     * ripulito ma intatto, così la validazione può scartarlo con un messaggio
     * sensato invece di vederselo mutilare.
     */
    public static function normalize(?string $value): string
    {
        $clean = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $value) ?? '');

        if (preg_match('/^IT(\d{11})$/', $clean, $matches) === 1) {
            return $matches[1];
        }

        return $clean;
    }

    /**
     * Le scritture con cui lo stesso numero può comparire in `clients.vat_number`.
     * Usate per ritrovare un cliente già esistente prima di crearne uno nuovo.
     *
     * @return array<int, string>
     */
    public static function variants(?string $value): array
    {
        $normalized = self::normalize($value);

        if ($normalized === '') {
            return [];
        }

        return array_values(array_unique(array_filter([
            $normalized,
            'IT'.$normalized,
            'it'.$normalized,
            trim((string) $value),
        ])));
    }
}
