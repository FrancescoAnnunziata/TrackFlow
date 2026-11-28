<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Intestazioni (dismesse)
    |--------------------------------------------------------------------------
    |
    | Le intestazioni ora stanno nella tabella `emittenti` e si modificano
    | dalla pagina Emittenti. Questo blocco lo legge soltanto la migrazione
    | 2026_11_28_140000_create_emittenti_table, una volta, per copiare i dati
    | del .env di produzione. Va tolto, insieme alle variabili G8LABS_* e
    | GIORGIO_* del .env, dopo il deploy che crea la tabella.
    |
    */

    'emittenti' => [

        'g8labs' => [
            'nome' => env('G8LABS_NOME', 'g8labs srl unipersonale'),
            'sottotitolo' => env('G8LABS_SOTTOTITOLO'),
            'partita_iva' => env('G8LABS_PARTITA_IVA'),
            'codice_fiscale' => env('G8LABS_CODICE_FISCALE'),
            'indirizzo' => env('G8LABS_INDIRIZZO'),
            'cap' => env('G8LABS_CAP'),
            'citta' => env('G8LABS_CITTA'),
            'provincia' => env('G8LABS_PROVINCIA'),
            'email' => env('G8LABS_EMAIL', env('MAIL_FROM_ADDRESS')),
            'telefono' => env('G8LABS_TELEFONO'),
            'pec' => env('G8LABS_PEC'),
            'iban' => env('G8LABS_IBAN'),
            // Percorso relativo a public/.
            'logo' => env('G8LABS_LOGO', 'images/LogoBlack.png'),
            // Dicitura fiscale in calce (es. regime forfettario, bollo, ...).
            'nota_fiscale' => env('G8LABS_NOTA_FISCALE'),
        ],

        'giorgio' => [
            'nome' => env('GIORGIO_NOME', 'Giorgio Giotto'),
            'sottotitolo' => env('GIORGIO_SOTTOTITOLO'),
            'partita_iva' => env('GIORGIO_PARTITA_IVA'),
            'codice_fiscale' => env('GIORGIO_CODICE_FISCALE'),
            'indirizzo' => env('GIORGIO_INDIRIZZO'),
            'cap' => env('GIORGIO_CAP'),
            'citta' => env('GIORGIO_CITTA'),
            'provincia' => env('GIORGIO_PROVINCIA'),
            'email' => env('GIORGIO_EMAIL'),
            'telefono' => env('GIORGIO_TELEFONO'),
            'pec' => env('GIORGIO_PEC'),
            'iban' => env('GIORGIO_IBAN'),
            'logo' => env('GIORGIO_LOGO'),
            'nota_fiscale' => env('GIORGIO_NOTA_FISCALE'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Condizioni stampate sul preventivo
    |--------------------------------------------------------------------------
    |
    | Valgono per tutte le intestazioni.
    |
    */

    // Giorni di validità dell'offerta a partire dalla data del preventivo.
    'validita_giorni' => (int) env('AZIENDA_VALIDITA_GIORNI', 30),

    'condizioni' => [
        'Le ore indicate sono una stima: la fatturazione avviene a consuntivo sulle ore effettivamente svolte, alla tariffa oraria concordata.',
        'Pagamento a 30 giorni data fattura, salvo diverso accordo scritto.',
        'L\'accettazione di questo preventivo, sottoscritta con firma grafica, vale come conferma d\'ordine.',
    ],

];
