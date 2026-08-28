<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\Import\EndpointInventoryCsvImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Importa il CSV di censimento endpoint da riga di comando, per automatizzare
 * il giro periodico (es. una cartella dove lo script PowerShell deposita i file).
 *
 * Idempotente: rilanciare lo stesso file aggiorna le rilevazioni gia' presenti
 * invece di duplicarle.
 */
class ImportEndpointInventory extends Command
{
    protected $signature = 'inventario:import
        {file : Percorso del CSV prodotto dallo script di censimento}
        {--client= : ID o nome del cliente a cui attribuire i dispositivi}
        {--user= : ID dell\'utente a cui attribuire le rilevazioni}';

    protected $description = 'Importa il censimento endpoint (CSV PowerShell) come rilevazioni di sicurezza.';

    public function handle(EndpointInventoryCsvImporter $importer): int
    {
        $file = (string) $this->argument('file');

        if (! is_readable($file)) {
            $this->error("File non leggibile: {$file}");

            return self::FAILURE;
        }

        $client = $this->resolveClient();

        if ($client === null) {
            $this->error('Cliente non trovato: indicare --client con l\'ID o il nome esatto.');

            return self::FAILURE;
        }

        try {
            $result = $importer->import($file, $client->id, $this->option('user') ? (int) $this->option('user') : null);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Dispositivi creati', 'Dispositivi aggiornati', 'Rilevazioni nuove', 'Rilevazioni aggiornate', 'Righe scartate', 'Criticità aperte'],
            [[
                $result['devices_created'],
                $result['devices_updated'],
                $result['checks_created'],
                $result['checks_updated'],
                $result['skipped'],
                $result['findings'],
            ]],
        );

        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return self::SUCCESS;
    }

    private function resolveClient(): ?Client
    {
        $option = $this->option('client');

        if (blank($option)) {
            return null;
        }

        return is_numeric($option)
            ? Client::find((int) $option)
            : Client::where('name', $option)->first();
    }
}
