<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Fatture in Cloud (API v2, OAuth2 Authorization Code flow).
    'fic' => [
        'client_id' => env('FIC_CLIENT_ID'),
        'client_secret' => env('FIC_CLIENT_SECRET'),
        // Deve combaciare ESATTAMENTE con il redirect registrato nell'app FIC.
        'redirect' => env('FIC_REDIRECT_URI'),
        'base_url' => env('FIC_BASE_URL', 'https://api-v2.fattureincloud.it'),
        // Scope: gestire le fatture emesse (a) + leggere i documenti ricevuti
        // e l'anagrafica fornitori (r) per il controllo finanziario. Cambiare
        // questo valore richiede un "Riconnetti" su Impostazioni → Fatture in
        // Cloud (nuovo consenso dell'utente).
        'scopes' => env('FIC_SCOPES', 'issued_documents.invoices:a issued_documents.credit_notes:r received_documents:r entity.suppliers:r'),
        // ID del tipo IVA "Escluso Art.15" nell'azienda FIC (per i rimborsi
        // spese). Per-azienda: leggibile solo con scope aggiuntivo, quindi
        // configurabile con default noto.
        'art15_vat_id' => (int) env('FIC_ART15_VAT_ID', 32),
        // ID del tipo IVA standard (22%) nell'azienda FIC. FIC richiede vat.id
        // su ogni riga: 0 è il 22% predefinito.
        'standard_vat_id' => (int) env('FIC_STANDARD_VAT_ID', 0),
        // Crea documenti come fattura elettronica (trasmissibile al SDI). Con
        // false FIC fa un documento cartaceo (solo invio per email).
        'e_invoice' => (bool) env('FIC_E_INVOICE', true),
        // ModalitaPagamento SDI di default per l'XML della fattura elettronica
        // (MP05 = bonifico). Il singolo cliente può sovrascriverla.
        'ei_payment_method' => env('FIC_EI_PAYMENT_METHOD', 'MP05'),
    ],

    // Anthropic (Claude): estrazione dati dalle fatture estere e assistente AI.
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-4-8'),

        // Prezzi in USD per 1.000.000 di token, per calcolare il costo di ogni
        // chiamata AI. I moltiplicatori sono i fattori Anthropic sui token in
        // cache (~0.1x lettura, ~1.25x scrittura). Da tenere allineati ai listini.
        'pricing' => [
            'claude-opus-4-8' => ['input' => 5.00, 'output' => 25.00],
            'claude-opus-5' => ['input' => 5.00, 'output' => 25.00],
            'claude-sonnet-5' => ['input' => 3.00, 'output' => 15.00],
            'claude-sonnet-4-6' => ['input' => 3.00, 'output' => 15.00],
            'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00],
        ],
        'cache_read_multiplier' => 0.1,
        'cache_write_multiplier' => 1.25,
    ],

];
