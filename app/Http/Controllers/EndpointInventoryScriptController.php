<?php

namespace App\Http\Controllers;

use App\Services\Security\EndpointScriptBuilder;
use Illuminate\Http\Response;

class EndpointInventoryScriptController extends Controller
{
    /**
     * Scarica lo script di censimento con la tabella OS_Supporto sempre
     * aggiornata alla data del download (vedi EndpointScriptBuilder).
     */
    public function download(EndpointScriptBuilder $builder): Response
    {
        // Strumento IT interno: non e' pertinente per gli account "cliente".
        abort_if(auth()->user()->isClient(), 403);

        return response($builder->build(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="Inventario-Sicurezza.ps1"',
        ]);
    }
}
