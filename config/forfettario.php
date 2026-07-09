<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Parametri del regime forfettario (Giorgio Giotto, P.IVA)
    |--------------------------------------------------------------------------
    |
    | Usati dalla dashboard finanziaria per stimare tasse e contributi sulla
    | parte forfettario. Il forfettario tassa una quota (coefficiente) del
    | fatturato incassato; su quella base si applicano contributi INPS (deducibili)
    | e poi l'imposta sostitutiva. La soglia limita la permanenza nel regime.
    |
    */

    'coefficiente_redditivita' => (float) env('FORFETTARIO_COEFFICIENTE', 0.67),

    'aliquota_imposta' => (float) env('FORFETTARIO_ALIQUOTA', 0.15),

    'inps_gestione_separata' => (float) env('FORFETTARIO_INPS', 0.2607),

    'limite_ricavi' => (float) env('FORFETTARIO_LIMITE', 85000),

];
