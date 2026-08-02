<?php

namespace App\Services;

use App\Enums\ReimbursementStatus;
use App\Enums\ReimbursementType;
use App\Models\Reimbursement;
use App\Models\TravelRate;
use App\Models\User;
use Illuminate\Support\Carbon;

class TravelReimbursementService
{
    /**
     * Crea (o aggiorna) il rimborso trasferta per un utente in un dato giorno a
     * partire da una tariffa della sua tabella. L'importo e' km * tariffa €/km.
     *
     * E' idempotente per (utente, giorno): un giorno ha una sola trasferta,
     * coerentemente col Luogo di lavoro giornaliero di Google Calendar. Lo stato
     * gestito a mano dall'admin non viene resettato sui rigeneri.
     */
    public function generate(User $user, TravelRate $rate, Carbon|string $date): Reimbursement
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        $reimbursement = Reimbursement::query()
            ->where('user_id', $user->id)
            ->where('type', ReimbursementType::Travel)
            ->whereDate('date', $date->toDateString())
            ->first()
            ?? new Reimbursement([
                'user_id' => $user->id,
                'type' => ReimbursementType::Travel,
                'date' => $date->toDateString(),
            ]);

        if (! $reimbursement->exists) {
            $reimbursement->status = ReimbursementStatus::Pending;
        }

        $km = (float) $rate->km;
        $rateValue = (float) ($user->km_rate ?? 0);

        $reimbursement->fill([
            'travel_type' => $rate->tipo,
            'from_location' => $rate->from_location,
            'to_location' => $rate->to_location,
            'purpose' => $rate->purpose,
            'km' => $km,
            'amount' => round($km * $rateValue, 2),
            'notes' => $rate->purpose,
        ])->save();

        return $reimbursement;
    }
}
