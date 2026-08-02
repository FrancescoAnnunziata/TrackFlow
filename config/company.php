<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Intestazione nota spese / rimborsi
    |--------------------------------------------------------------------------
    |
    | Dati del destinatario ("Spett.le Società") stampati in testa alla nota
    | spese rimborsi chilometrici. Sovrascrivibili da .env in produzione.
    |
    */

    'name' => env('COMPANY_NAME', 'G8Labs SRL'),
    'address' => env('COMPANY_ADDRESS', 'Pietro Pisa 74'),
    'city' => env('COMPANY_CITY', '25014, Castenedolo'),
    'vat' => env('COMPANY_VAT', '4704320987'),
];
