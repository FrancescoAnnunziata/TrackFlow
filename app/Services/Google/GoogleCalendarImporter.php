<?php

namespace App\Services\Google;

use App\Models\TravelRate;
use App\Models\User;
use App\Services\TravelReimbursementService;
use Illuminate\Support\Carbon;

class GoogleCalendarImporter
{
    public function __construct(
        private readonly GoogleCalendarClient $calendar,
        private readonly TravelReimbursementService $reimbursements,
    ) {}

    /**
     * Legge i Luoghi di lavoro dell'utente per il mese indicato e genera le
     * trasferte per i giorni la cui etichetta corrisponde a una voce della sua
     * tabella (match case-insensitive sul "Tipo trasferta"). Restituisce un
     * riepilogo con i giorni generati e le etichette non riconosciute.
     *
     * @return array{generated: int, days: array<int, string>, unmatched: array<string, array<int, string>>}
     */
    public function importMonth(User $user, int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $locations = $this->calendar->workingLocations($user, $start, $end);

        // Mappa delle tariffe dell'utente per chiave normalizzata.
        $rates = TravelRate::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy(fn (TravelRate $r): string => $this->normalize($r->tipo));

        $generated = [];
        $unmatched = [];

        foreach ($locations as $date => $label) {
            $rate = $rates->get($this->normalize($label));

            if ($rate === null) {
                $unmatched[$label][] = $date;

                continue;
            }

            $this->reimbursements->generate($user, $rate, Carbon::parse($date));
            $generated[] = $date;
        }

        sort($generated);

        return [
            'generated' => count($generated),
            'days' => $generated,
            'unmatched' => $unmatched,
        ];
    }

    private function normalize(string $value): string
    {
        return mb_strtoupper(trim($value));
    }
}
