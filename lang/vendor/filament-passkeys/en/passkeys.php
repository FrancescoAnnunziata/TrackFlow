<?php

// Testi del plugin passkey in italiano. Stanno sotto «en» perché TrackFlow gira
// con APP_LOCALE=en (le stringhe di Filament restano quelle inglesi di serie),
// ma questi li legge chi entra, e devono dire le cose come le dice il manuale.

return [
    'provider' => [
        'label' => 'Usa una passkey',
    ],

    'management' => [
        'heading' => 'Passkey',
        'description' => 'Con una passkey entri con l\'impronta, il volto o il PIN del dispositivo, senza digitare il codice.',
        'enabled' => 'Attive',
        'disabled' => 'Nessuna',
        'empty' => 'Nessuna passkey registrata.',
        'add' => 'Aggiungi una passkey',
        'waiting' => 'In attesa del dispositivo…',
        'default_name' => 'Passkey',
        'rename' => 'Rinomina',
        'renamed' => 'Passkey rinominata.',
        'delete' => 'Elimina',
        'delete_confirmation' => 'Vuoi davvero eliminare la passkey «:name»?',
        'never_used' => 'Mai usata',
        'columns' => [
            'name' => 'Nome',
            'last_used' => 'Ultimo uso',
            'actions' => 'Azioni',
        ],
        'registered' => 'Passkey registrata.',
        'deleted' => 'Passkey eliminata.',
    ],

    'challenge' => [
        'unsupported' => 'Questo browser non supporta le passkey.',
        'failed' => 'Non è stato possibile verificare la passkey. Riprova.',
        'callout' => [
            'heading' => 'Accedi con una passkey',
            'description' => 'Premi «:action» e scegli la passkey dal dispositivo o dal gestore delle password.',
            'waiting' => 'In attesa della passkey…',
        ],
    ],

    'register' => [
        'name_label' => 'Nome della passkey',
    ],

    'login' => [
        'button' => 'Accedi con passkey',
        'failed' => 'Non è stato possibile verificare la passkey. Riprova.',
    ],
];
