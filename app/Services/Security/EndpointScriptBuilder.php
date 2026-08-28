<?php

namespace App\Services\Security;

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Compila lo script PowerShell di censimento a partire dal template in
 * resources/scripts, sostituendo la tabella delle date di fine supporto
 * Windows con quella corrente da config/inventario_endpoint.php.
 *
 * Cosi' la tabella $EOL nello script non si aggiorna piu' a mano su una
 * chiavetta USB: si aggiorna la config di TrackFlow, e ogni download
 * successivo la include gia' aggiornata alla data del download.
 */
class EndpointScriptBuilder
{
    private const STUB_PATH = 'scripts/Inventario-Sicurezza.ps1.stub';

    public function build(): string
    {
        $stub = resource_path(self::STUB_PATH);

        if (! is_readable($stub)) {
            throw new RuntimeException("Template script non trovato: {$stub}");
        }

        return str_replace(
            ['{{TRACKFLOW:EOL_TABLE}}', '{{TRACKFLOW:WIN10_EOL}}', '{{TRACKFLOW:GENERATED_AT}}'],
            [$this->eolTableBlock(), $this->windows10Eol(), Carbon::now()->format('Y-m-d H:i')],
            file_get_contents($stub)
        );
    }

    /**
     * Righe della tabella $EOL nel formato atteso dallo script:
     *     '22H2' = '2024-10-08'   # Win11 22H2 Home/Pro
     */
    private function eolTableBlock(): string
    {
        $dates = (array) config('inventario_endpoint.windows_eol.dates', []);

        if ($dates === []) {
            throw new RuntimeException('Nessuna data di fine supporto configurata (inventario_endpoint.windows_eol.dates).');
        }

        return collect($dates)
            ->map(fn (string $date, string $version): string => sprintf(
                "    '%s' = '%s'   # Win11 %s Home/Pro",
                $version,
                $date,
                $version,
            ))
            ->implode("\n");
    }

    private function windows10Eol(): string
    {
        $date = config('inventario_endpoint.windows_eol.windows10_eol');

        if (blank($date)) {
            throw new RuntimeException('Data di fine supporto Windows 10 non configurata (inventario_endpoint.windows_eol.windows10_eol).');
        }

        return (string) $date;
    }
}
