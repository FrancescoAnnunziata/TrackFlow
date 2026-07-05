<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Unisce i clienti-doppione creati dall'import FIC con quelli già esistenti.
 *
 * L'import (--create-clients) crea un cliente con la ragione sociale legale di
 * FIC quando non trova corrispondenza per nome col cliente "breve" già presente
 * (es. "Fedespedi" ≠ "Federazione Nazionale..."). Questo comando riconcilia le
 * coppie: tiene il cliente esistente (con ore/spese/referenti), gli sposta le
 * fatture, gli copia i dati fiscali e adotta la ragione sociale legale, poi
 * elimina il doppione.
 *
 * Guidato da argomenti "NomeEsistente=PIVA" così è riproducibile identico in
 * locale e in produzione. Idempotente: se il doppione non esiste più, salta.
 *
 * Esempio:
 *   php artisan clients:merge-imported "Fedespedi=09519690961" "Alsea=IT14322120966"
 */
class MergeImportedClients extends Command
{
    protected $signature = 'clients:merge-imported {pairs* : Coppie "NomeEsistente=PIVAdelDoppione"}';

    protected $description = 'Unisce i clienti-doppione creati dall\'import FIC con quelli esistenti (per P.IVA).';

    /**
     * Campi fiscali/anagrafici copiati dal doppione FIC verso il cliente tenuto.
     */
    private const FIELDS = [
        'name', 'entity_type', 'vat_number', 'tax_code',
        'address_street', 'address_postal_code', 'address_city', 'address_province',
        'country', 'country_iso', 'certified_email', 'ei_code',
    ];

    public function handle(): int
    {
        $merged = 0;

        foreach ($this->argument('pairs') as $pair) {
            [$name, $vat] = array_pad(explode('=', $pair, 2), 2, '');
            $name = trim($name);
            $vat = trim($vat);

            if ($name === '' || $vat === '') {
                $this->warn("Coppia non valida: '{$pair}' (atteso Nome=PIVA).");

                continue;
            }

            $keep = Client::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

            if ($keep === null) {
                $this->line("• '{$name}': nessun cliente esistente con questo nome — salto (forse già unito).");

                continue;
            }

            $dup = Client::where('vat_number', $vat)->where('id', '!=', $keep->id)->first();

            if ($dup === null) {
                $this->line("• '{$name}': nessun doppione con P.IVA {$vat} — salto (già unito).");

                continue;
            }

            try {
                $this->mergePair($keep, $dup);
                $this->info("✓ Unito '{$dup->name}' → '{$keep->name}' (P.IVA {$vat}).");
                $merged++;
            } catch (Throwable $e) {
                $this->error("✗ '{$name}': merge fallito — {$e->getMessage()}");
            }
        }

        $this->info("Merge completati: {$merged}.");

        return self::SUCCESS;
    }

    private function mergePair(Client $keep, Client $dup): void
    {
        DB::transaction(function () use ($keep, $dup): void {
            Invoice::where('client_id', $dup->id)->update(['client_id' => $keep->id]);

            $data = [];
            foreach (self::FIELDS as $field) {
                if (filled($dup->$field)) {
                    $data[$field] = $dup->$field;
                }
            }
            if (blank($keep->email) && filled($dup->email)) {
                $data['email'] = $dup->email;
            }

            $keep->update($data);
            $dup->delete();
        });
    }
}
