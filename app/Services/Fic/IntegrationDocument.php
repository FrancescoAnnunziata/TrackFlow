<?php

namespace App\Services\Fic;

/**
 * Legge l'XML di una fattura elettronica ricevuta e dice se è un'integrazione
 * dell'esterometro, e quale fattura estera integra.
 *
 * Il dato affidabile è nell'XML, non nei campi di Fatture in Cloud: il tipo
 * documento (TD17 servizi dall'estero, TD18 beni intra-UE, TD19 beni art. 17
 * c.2) e il riferimento alla fattura del fornitore in DatiFattureCollegate.
 * L'importo da solo non basta: SiteGround ha cinque fatture da 12,99 €.
 */
class IntegrationDocument
{
    public const TYPES = ['TD17', 'TD18', 'TD19'];

    /**
     * @return array{type: string, number: ?string, date: ?string}|null
     *                                                                  null se l'XML non è un'integrazione (o non si legge)
     */
    public static function parse(string $xml): ?array
    {
        $xml = self::stripSignature($xml);

        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, options: LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($doc === false) {
            return null;
        }

        // Gli elementi interni non hanno namespace, la radice sì (ns3, p, …):
        // XPath su local-name() regge qualunque prefisso.
        $type = self::first($doc, 'TipoDocumento');
        if (! in_array($type, self::TYPES, true)) {
            return null;
        }

        return [
            'type' => $type,
            'number' => self::first($doc, 'IdDocumento', 'DatiFattureCollegate'),
            'date' => self::first($doc, 'Data', 'DatiFattureCollegate'),
        ];
    }

    private static function first(\SimpleXMLElement $doc, string $name, ?string $parent = null): ?string
    {
        $path = $parent !== null
            ? "//*[local-name()='{$parent}']/*[local-name()='{$name}']"
            : "//*[local-name()='{$name}']";

        $nodes = $doc->xpath($path) ?: [];
        $value = isset($nodes[0]) ? trim((string) $nodes[0]) : '';

        return $value !== '' ? $value : null;
    }

    /**
     * Un .xml.p7m è l'XML dentro una busta firmata: se c'è, si prende la parte
     * fra la dichiarazione XML e la chiusura del documento.
     */
    private static function stripSignature(string $raw): string
    {
        if (preg_match('/<\?xml.*<\/[A-Za-z0-9]*:?FatturaElettronica>/s', $raw, $m) === 1) {
            return $m[0];
        }

        return $raw;
    }
}
