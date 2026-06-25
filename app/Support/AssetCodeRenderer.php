<?php

namespace App\Support;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Picqer\Barcode\BarcodeGeneratorPNG;

/**
 * Genera localmente le immagini di barcode (Code128) e QR code per le
 * etichette degli asset. Le immagini sono restituite come data-URI base64,
 * pronte per essere inserite inline in una vista HTML stampabile: la
 * stampante Dymo deve solo stampare, tutta la generazione e' lato software.
 */
class AssetCodeRenderer
{
    /**
     * Barcode Code128 contenente l'asset_code, come data-URI PNG.
     */
    public static function barcodeDataUri(string $value, int $widthFactor = 2, int $height = 50): string
    {
        $generator = new BarcodeGeneratorPNG();
        $png = $generator->getBarcode($value, $generator::TYPE_CODE_128, $widthFactor, $height);

        return 'data:image/png;base64,'.base64_encode($png);
    }

    /**
     * QR code contenente un URL sicuro, come data-URI PNG.
     */
    public static function qrDataUri(string $value, int $size = 140): string
    {
        $result = (new Builder(
            data: $value,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 4,
        ))->build();

        return $result->getDataUri();
    }
}
