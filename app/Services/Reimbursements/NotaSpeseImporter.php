<?php

namespace App\Services\Reimbursements;

use App\Enums\ReimbursementType;
use App\Models\Reimbursement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Crea i rimborsi spese dalle note spese Excel (vedi NotaSpeseExcel): un
 * rimborso per mese, intestato alla persona scelta, con dentro un costo per i
 * km e uno per ciascuna delle altre spese.
 *
 * Crea solo quello che manca: se quella persona ha già un rimborso trasferta
 * nello stesso mese, il file si salta. Ricaricare gli stessi Excel non fa danni.
 */
class NotaSpeseImporter
{
    /**
     * Le altre spese della nota (abbonamenti, pasti, ...) non dicono di che
     * tipo sono: finiscono qui, e il resoconto chiede di controllarle.
     */
    public const CONTO_ALTRE_SPESE = 'Altro';

    public const CONTO_KM = 'Trasferte';

    /**
     * @param  array<int, array{nome: string, percorso: string, allegato: string}>  $file
     *                                                                                     nome originale, percorso da leggere, allegato da salvare sul rimborso
     * @return array{creati: array<int, string>, saltati: array<int, string>, errori: array<int, string>, daControllare: int, allegatiUsati: array<int, string>}
     */
    public function importa(array $file, User $persona): array
    {
        $esito = ['creati' => [], 'saltati' => [], 'errori' => [], 'daControllare' => 0, 'allegatiUsati' => []];

        foreach ($file as ['nome' => $nome, 'percorso' => $percorso, 'allegato' => $allegato]) {
            try {
                $nota = NotaSpeseExcel::leggi($percorso, $nome);
            } catch (RuntimeException $e) {
                $esito['errori'][] = "{$nome}: {$e->getMessage()}";

                continue;
            }

            if (mb_strtolower($nota->persona) !== mb_strtolower($persona->full_name)) {
                $esito['errori'][] = "{$nome}: la nota è di {$nota->persona}, non di {$persona->full_name}.";

                continue;
            }

            $esistente = Reimbursement::query()
                ->where('user_id', $persona->id)
                ->where('type', ReimbursementType::Travel)
                ->whereYear('date', $nota->mese->year)
                ->whereMonth('date', $nota->mese->month)
                ->first();

            if ($esistente !== null) {
                $esito['saltati'][] = sprintf(
                    '%s: c\'è già il rimborso di %s (€ %s)%s.',
                    $nome, $nota->etichettaMese(), number_format((float) $esistente->amount, 2, ',', '.'),
                    abs((float) $esistente->amount - $nota->totale()) > 0.01
                        ? ', diverso dal file (€ '.number_format($nota->totale(), 2, ',', '.').'): controlla quale è giusto'
                        : '',
                );

                continue;
            }

            $this->crea($nota, $persona, $allegato);
            $esito['allegatiUsati'][] = $allegato;

            $esito['creati'][] = sprintf('%s: € %s', $nota->etichettaMese(), number_format($nota->totale(), 2, ',', '.'));
            $esito['daControllare'] += count($nota->altreSpese);
        }

        return $esito;
    }

    private function crea(NotaSpeseExcel $nota, User $persona, string $allegato): void
    {
        $data = $nota->mese->copy()->endOfMonth()->toDateString();

        DB::transaction(function () use ($nota, $persona, $allegato, $data): void {
            $rimborso = Reimbursement::create([
                'user_id' => $persona->id,
                'type' => ReimbursementType::Travel,
                'date' => $data,
                'amount' => $nota->totale(),
                'notes' => 'Rimborso spese '.$nota->etichettaMese(),
                // Il file caricato resta come giustificativo del rimborso.
                'attachments' => [$allegato],
            ]);

            if ($nota->km > 0) {
                $rimborso->costi()->create([
                    'date' => $data,
                    'description' => sprintf(
                        'Rimborso km %s (%s km × %s €/km)',
                        $nota->etichettaMese(),
                        number_format($nota->km, 0, ',', '.'),
                        rtrim(rtrim(number_format($nota->tariffaKm, 4, ',', ''), '0'), ','),
                    ),
                    'category' => self::CONTO_KM,
                    'amount' => $nota->importoKm(),
                    'vat_amount' => 0,
                ]);
            }

            foreach ($nota->altreSpese as $spesa) {
                $rimborso->costi()->create([
                    'date' => $nota->mese->copy()->day(min($spesa['giorno'], $nota->mese->daysInMonth))->toDateString(),
                    'description' => $spesa['descrizione'],
                    'category' => self::CONTO_ALTRE_SPESE,
                    'amount' => $spesa['importo'],
                    'vat_amount' => 0,
                ]);
            }
        });
    }
}
