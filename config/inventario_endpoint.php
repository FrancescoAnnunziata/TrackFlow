<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Import del censimento endpoint (script PowerShell)
    |--------------------------------------------------------------------------
    |
    | Lo script di censimento produce un CSV (delimitatore ";", UTF-8 con BOM)
    | con una riga per dispositivo ad ogni giro. Qui stanno i parametri che
    | servono a interpretare quei valori e a decidere quando un campo e' in
    | stato di rischio.
    |
    */

    'csv' => [
        'delimiter' => ';',
        // Formati data accettati: lo script scrive "Y-m-d" e "Y-m-d H:i".
        'date_formats' => ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd/m/Y H:i', 'd/m/Y'],
        // Valori che lo script usa per "non rilevabile": diventano NULL.
        'null_values' => ['', 'n/d', 'n/a', 'nd', 'non disponibile', 'n/d (serve admin)'],
    ],

    /*
    | Seriali "di fabbrica" che non identificano nulla: quando il BIOS riporta
    | uno di questi valori il dispositivo viene riconosciuto per hostname.
    */
    'serial_placeholders' => [
        'default string',
        'to be filled by o.e.m.',
        'system serial number',
        'none',
        '0123456789',
        'invalid',
        'not specified',
        'not applicable',
    ],

    /*
    | Account ammessi nel gruppo Administrators locale. Il confronto e' fatto
    | sulla parte dopo il backslash (DOMINIO\utente -> utente), senza distinzione
    | fra maiuscole e minuscole. Tutto cio' che non e' in elenco viene segnalato.
    */
    'admin_group_allowlist' => [
        'administrator',
        'administrators',
        'amministratore',
        'domain admins',
        'admin domini',
    ],

    /*
    | Soglie usate per derivare i controlli booleani gia' presenti sulla scheda
    | (os_updated, antivirus_updated, ...) dai campi numerici del CSV.
    */
    'thresholds' => [
        'max_days_since_patch' => 35,
        'max_av_signature_age_days' => 7,
        'max_days_since_reboot' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Date di fine supporto Windows, per lo script di censimento
    |--------------------------------------------------------------------------
    |
    | Fonte di verita' per la tabella $EOL che lo script PowerShell usa per
    | calcolare OS_Supporto. Non si modifica piu' il file .ps1 a mano: si
    | aggiorna qui, e chi scarica lo script da Security check → Scarica
    | script riceve sempre la tabella corrente (vedi EndpointScriptBuilder).
    |
    | Fonte ufficiale, da controllare prima di ogni aggiornamento (tabella
    | "Releases", non "Support Dates" che riporta solo il primo/ultimo giorno
    | dell'intera linea Windows 11 senza distinguere le versioni):
    | https://learn.microsoft.com/en-us/lifecycle/products/windows-11-home-and-pro
    | Le date differiscono per edizione Enterprise/Education: quella pagina
    | vale solo per Home/Pro, cioe' quello che gira sulle macchine censite.
    */
    'windows_eol' => [
        // OS_Versione (il "feature update", es. 24H2) => data di fine supporto
        // per i canali Home/Pro. Verificate su learn.microsoft.com/lifecycle
        // (link sopra) il 2026-08-28.
        'dates' => [
            '21H2' => '2023-10-10',
            '22H2' => '2024-10-08',
            '23H2' => '2025-11-11',
            '24H2' => '2026-10-13',
            '25H2' => '2027-10-12',
            '26H1' => '2028-03-14',
        ],
        'windows10_eol' => '2025-10-14',
    ],
];
