<?php

namespace App\Http\Controllers;

use App\Services\ReimbursementNoteExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReimbursementExportController extends Controller
{
    /**
     * Scarica la "Nota spese rimborsi chilometrici" (XLSX) dell'utente
     * autenticato per il mese/anno indicati, opzionalmente filtrata su un
     * cliente. I clienti non hanno rimborsi: l'accesso e' negato.
     */
    public function export(Request $request, ReimbursementNoteExporter $exporter): BinaryFileResponse
    {
        $user = $request->user();

        abort_if($user->isClient(), 403);

        $validated = $request->validate([
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
        ]);

        $path = $exporter->export(
            $user,
            (int) $validated['year'],
            (int) $validated['month'],
            isset($validated['client_id']) ? (int) $validated['client_id'] : null,
        );

        $label = Carbon::create((int) $validated['year'], (int) $validated['month'], 1)
            ->locale('it')
            ->translatedFormat('M_Y');

        return response()
            ->download($path, 'nota_spese_'.$label.'.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }
}
