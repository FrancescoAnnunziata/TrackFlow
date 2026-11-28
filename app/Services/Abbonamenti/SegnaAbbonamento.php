<?php

namespace App\Services\Abbonamenti;

use App\Models\Abbonamento;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Segna un addebito (movimento bancario o fattura passiva) come abbonamento.
 *
 * È lo stesso punto per l'azione del pannello e per la proposta confermata
 * dall'assistente, così le due strade non possono comportarsi in modo diverso.
 *
 * Se l'abbonamento esiste già (scelto esplicitamente, o con lo stesso nome)
 * l'addebito è un rinnovo: aggiorna data e importo invece di creare un doppione.
 */
class SegnaAbbonamento
{
    public function segna(
        string $name,
        string $cadence,
        CarbonInterface $chargedOn,
        ?float $amount,
        ?int $supplierId,
        ?int $existingId = null,
    ): Abbonamento {
        $name = trim($name);
        $chargedOn = CarbonImmutable::parse($chargedOn)->startOfDay();
        Abbonamento::monthsFor($cadence); // valida la cadenza prima di scrivere

        $abbonamento = $existingId !== null
            ? Abbonamento::findOrFail($existingId)
            : Abbonamento::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        if ($abbonamento === null) {
            return Abbonamento::create([
                'name' => $name,
                'supplier_id' => $supplierId,
                'cadence' => $cadence,
                'amount' => $amount,
                'last_charged_on' => $chargedOn,
                'next_renewal_on' => Abbonamento::nextRenewalAfter($chargedOn, $cadence),
            ]);
        }

        $abbonamento->cadence = $cadence;
        $abbonamento->supplier_id ??= $supplierId;

        // Un addebito più vecchio dell'ultimo noto (si stanno segnando movimenti
        // arretrati) non deve far tornare indietro la scadenza.
        if ($abbonamento->last_charged_on === null || $chargedOn->gte($abbonamento->last_charged_on)) {
            $abbonamento->last_charged_on = $chargedOn;
            $abbonamento->next_renewal_on = Abbonamento::nextRenewalAfter($chargedOn, $cadence);
            if ($amount !== null) {
                $abbonamento->amount = $amount;
            }
        }

        $abbonamento->save();

        return $abbonamento;
    }
}
