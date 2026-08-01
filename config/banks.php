<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Preset di import CSV per banca
    |--------------------------------------------------------------------------
    |
    | Ogni preset (chiave = bank_key del conto) definisce il formato del file e
    | la mappatura colonne di default. Sono solo dei valori iniziali: nel modale
    | di import l'utente può sovrascrivere ogni campo per adattarsi all'export
    | effettivo della banca.
    |
    | amount_mode:
    |   - 'signed'     una sola colonna importo, già con segno (+/-).
    |   - 'dare_avere' due colonne separate: avere = entrata, dare = uscita.
    |
    */

    'presets' => [

        'vivid' => [
            'label' => 'Vivid Business',
            'delimiter' => ',',
            'decimal' => '.',
            'thousands' => ',',
            'date_format' => 'd-m-Y',
            'amount_mode' => 'signed',
            // Vivid cambia intestazioni fra i tipi di estratto: si elencano le
            // alternative separate da "|", vince la prima presente nel file.
            'columns' => [
                'booked_at' => 'Completed date|Transaction date',
                'value_date' => '',
                'amount' => 'Payment amount',
                'description' => 'Reference',
                'counterparty' => 'Counterparty name',
                'reference' => 'Internal operation id',
                'dare' => '',
                'avere' => '',
            ],
        ],

        'inbank' => [
            'label' => 'InBank',
            'delimiter' => ';',
            'decimal' => ',',
            'thousands' => '.',
            'date_format' => 'd/m/Y',
            'amount_mode' => 'dare_avere',
            'columns' => [
                'booked_at' => 'DATA',
                'value_date' => 'VALUTA',
                'amount' => '',
                'description' => 'DESCRIZIONE_OPERAZIONE',
                'counterparty' => 'CAUSALE_ABI',
                'reference' => '',
                'dare' => 'DARE',
                'avere' => 'AVERE',
            ],
        ],

        'directa' => [
            'label' => 'Directa',
            'delimiter' => ';',
            'decimal' => '.',
            'thousands' => '',
            'date_format' => 'd-m-Y',
            'amount_mode' => 'signed',
            'columns' => [
                'booked_at' => 'Data operazione',
                'value_date' => 'Data valuta',
                'amount' => 'Importo euro',
                'description' => 'Tipo operazione',
                'counterparty' => 'Descrizione',
                'reference' => 'Protocollo',
                'dare' => '',
                'avere' => '',
            ],
        ],

        'generic' => [
            'label' => 'Generico',
            'delimiter' => ';',
            'decimal' => ',',
            'thousands' => '.',
            'date_format' => 'd/m/Y',
            'amount_mode' => 'signed',
            'columns' => [
                'booked_at' => 'Data',
                'value_date' => '',
                'amount' => 'Importo',
                'description' => 'Descrizione',
                'counterparty' => '',
                'reference' => '',
                'dare' => '',
                'avere' => '',
            ],
        ],

    ],

];
