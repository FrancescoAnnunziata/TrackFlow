<?php

namespace App\Support;

/**
 * Paesi per l'anagrafica fornitori: codice ISO 3166-1 alpha-2 → nome italiano.
 *
 * Il codice è il dato che conta (decide se una fattura passiva è estera e se il
 * fornitore è UE o extra-UE, cioè cosa ne fa il commercialista per
 * l'esterometro); il nome è solo l'etichetta che si legge.
 */
class Paesi
{
    /** Stati membri dell'Unione europea, Italia compresa. */
    public const UE = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    private const NOMI = [
        'IT' => 'Italia',
        // Unione europea
        'AT' => 'Austria',
        'BE' => 'Belgio',
        'BG' => 'Bulgaria',
        'CY' => 'Cipro',
        'CZ' => 'Repubblica Ceca',
        'DE' => 'Germania',
        'DK' => 'Danimarca',
        'EE' => 'Estonia',
        'ES' => 'Spagna',
        'FI' => 'Finlandia',
        'FR' => 'Francia',
        'GR' => 'Grecia',
        'HR' => 'Croazia',
        'HU' => 'Ungheria',
        'IE' => 'Irlanda',
        'LT' => 'Lituania',
        'LU' => 'Lussemburgo',
        'LV' => 'Lettonia',
        'MT' => 'Malta',
        'NL' => 'Paesi Bassi',
        'PL' => 'Polonia',
        'PT' => 'Portogallo',
        'RO' => 'Romania',
        'SE' => 'Svezia',
        'SI' => 'Slovenia',
        'SK' => 'Slovacchia',
        // Fuori dall'Unione
        'AE' => 'Emirati Arabi Uniti',
        'AU' => 'Australia',
        'CA' => 'Canada',
        'CH' => 'Svizzera',
        'CN' => 'Cina',
        'GB' => 'Regno Unito',
        'HK' => 'Hong Kong',
        'IL' => 'Israele',
        'IN' => 'India',
        'JP' => 'Giappone',
        'KR' => 'Corea del Sud',
        'NO' => 'Norvegia',
        'NZ' => 'Nuova Zelanda',
        'SG' => 'Singapore',
        'SM' => 'San Marino',
        'TR' => 'Turchia',
        'TW' => 'Taiwan',
        'UA' => 'Ucraina',
        'US' => 'Stati Uniti',
    ];

    /**
     * Opzioni per una Select: Italia in testa, poi gli altri in ordine di nome.
     *
     * @return array<string, string>
     */
    public static function opzioni(): array
    {
        $altri = self::NOMI;
        unset($altri['IT']);
        asort($altri);

        return ['IT' => 'Italia'] + $altri;
    }

    public static function nome(?string $iso): ?string
    {
        return self::NOMI[strtoupper((string) $iso)] ?? null;
    }

    public static function isUe(?string $iso): bool
    {
        return in_array(strtoupper((string) $iso), self::UE, true);
    }

    /** «Italia», «UE» o «Extra-UE»: la colonna che il commercialista guarda per prima. */
    public static function area(?string $iso): string
    {
        $iso = strtoupper((string) $iso);

        return match (true) {
            $iso === 'IT' => 'Italia',
            self::isUe($iso) => 'UE',
            default => 'Extra-UE',
        };
    }
}
