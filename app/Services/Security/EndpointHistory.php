<?php

namespace App\Services\Security;

use App\Models\Device;
use App\Models\DeviceSecurityCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Legge l'evoluzione nel tempo dei campi critici di un dispositivo.
 *
 * Lavora sulla serie delle rilevazioni (device_security_checks) in ordine
 * cronologico e risponde alle domande che servono in pratica: quando un campo
 * ha cambiato stato, da quante rilevazioni consecutive e' in rischio, da quanti
 * giorni dura la situazione.
 */
class EndpointHistory
{
    /** @var array<int, Collection<int, DeviceSecurityCheck>> */
    private array $cache = [];

    /**
     * Serie di uno dei campi in DeviceSecurityCheck::CRITICAL_CHECKS.
     *
     * @return Collection<int, array{check_id: int, at: Carbon, state: string, detail: ?string}>
     */
    public function series(Device $device, string $key): Collection
    {
        return $this->checks($device)
            ->map(function (DeviceSecurityCheck $check) use ($key): array {
                $evaluation = $check->evaluateCritical($key);

                return [
                    'check_id' => $check->id,
                    'at' => $check->checked_at,
                    'state' => $evaluation['state'],
                    'detail' => $evaluation['detail'],
                ];
            })
            ->values();
    }

    /**
     * Solo i punti in cui lo stato e' cambiato rispetto alla rilevazione
     * precedente, con il valore di partenza. Il primo elemento e' la prima
     * rilevazione disponibile (from = null).
     *
     * @return Collection<int, array{at: Carbon, from: ?string, to: string, detail: ?string}>
     */
    public function transitions(Device $device, string $key): Collection
    {
        $transitions = collect();
        $previous = null;

        foreach ($this->series($device, $key) as $point) {
            if ($previous === null || $point['state'] !== $previous) {
                $transitions->push([
                    'at' => $point['at'],
                    'from' => $previous,
                    'to' => $point['state'],
                    'detail' => $point['detail'],
                ]);
            }

            $previous = $point['state'];
        }

        return $transitions;
    }

    /**
     * Numero di rilevazioni consecutive, a partire dall'ultima, in cui il campo
     * risulta in rischio. 0 se l'ultima rilevazione e' a posto o non valutata.
     */
    public function riskStreak(Device $device, string $key): int
    {
        $streak = 0;

        foreach ($this->series($device, $key)->reverse() as $point) {
            if ($point['state'] !== DeviceSecurityCheck::STATE_RISK) {
                break;
            }

            $streak++;
        }

        return $streak;
    }

    /**
     * Data della prima rilevazione della serie di rischio ancora in corso:
     * "in questo stato da...". null se l'ultima rilevazione non e' in rischio.
     */
    public function riskSince(Device $device, string $key): ?Carbon
    {
        $since = null;

        foreach ($this->series($device, $key)->reverse() as $point) {
            if ($point['state'] !== DeviceSecurityCheck::STATE_RISK) {
                break;
            }

            $since = $point['at'];
        }

        return $since;
    }

    /** Giorni trascorsi dall'inizio della serie di rischio in corso. */
    public function daysInRisk(Device $device, string $key): ?int
    {
        $since = $this->riskSince($device, $key);

        // diffInDays ritorna un float in Carbon 3: i giorni interi bastano.
        return $since === null ? null : (int) floor(abs($since->diffInDays(now())));
    }

    /**
     * Riepilogo di tutti i campi critici del dispositivo: stato attuale,
     * quante rilevazioni consecutive e da quando.
     *
     * @return array<string, array{label: string, state: string, detail: ?string, streak: int, since: ?Carbon, days: ?int}>
     */
    public function summary(Device $device): array
    {
        $summary = [];

        foreach (DeviceSecurityCheck::CRITICAL_CHECKS as $key => [$label]) {
            $series = $this->series($device, $key);
            $last = $series->last();

            $summary[$key] = [
                'label' => $label,
                'state' => $last['state'] ?? DeviceSecurityCheck::STATE_UNKNOWN,
                'detail' => $last['detail'] ?? null,
                'streak' => $this->riskStreak($device, $key),
                'since' => $this->riskSince($device, $key),
                'days' => $this->daysInRisk($device, $key),
            ];
        }

        return $summary;
    }

    /**
     * Rilevazioni del dispositivo in ordine cronologico, caricate una sola
     * volta per istanza: summary() interroga sei campi sulla stessa serie.
     *
     * @return Collection<int, DeviceSecurityCheck>
     */
    private function checks(Device $device): Collection
    {
        return $this->cache[$device->id] ??= $device->securityChecks()
            ->orderBy('checked_at')
            ->get();
    }
}
