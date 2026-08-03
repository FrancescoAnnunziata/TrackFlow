<?php

namespace App\Services\Quotes;

use App\Models\Quote;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Rende il preventivo in PDF: la stessa identica pagina che il cliente ha letto
 * e firmato, con la firma incisa dentro.
 */
class QuotePdf
{
    /**
     * Byte del PDF, generati al volo.
     */
    public static function render(Quote $quote): string
    {
        $quote->loadMissing(['client', 'user', 'acceptedBy']);

        return Pdf::loadView('quotes.pdf', ['quote' => $quote])
            ->setPaper('a4')
            ->output();
    }

    /**
     * Congela il PDF su disco privato e lo aggancia al preventivo. Chiamato al
     * momento della firma: da lì in poi quel file è la copia che fa fede.
     */
    public static function store(Quote $quote): string
    {
        $path = self::pathFor($quote);

        Storage::disk(Quote::DOCUMENTS_DISK)->put($path, self::render($quote));

        $quote->forceFill(['pdf_path' => $path])->save();

        return $path;
    }

    /**
     * Il PDF salvato del preventivo firmato, rigenerato se il file è sparito.
     */
    public static function ensureStored(Quote $quote): string
    {
        $disk = Storage::disk(Quote::DOCUMENTS_DISK);

        if ($quote->pdf_path && $disk->exists($quote->pdf_path)) {
            return $quote->pdf_path;
        }

        return self::store($quote);
    }

    private static function pathFor(Quote $quote): string
    {
        return "quotes/{$quote->getKey()}/{$quote->pdfFileName()}";
    }
}
