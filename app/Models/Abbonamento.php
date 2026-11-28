<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Abbonamento a un fornitore (software, domini, hosting) che si rinnova da sé
 * ogni tre mesi o ogni anno. Serve a una cosa sola: ricordare il rinnovo una
 * settimana prima, quando c'è ancora tempo per disdirlo.
 *
 * Non è da confondere con le fatture «da abbonamento» di Personal Ticketing,
 * che sono ricavi verso i clienti (docs/api-abbonamenti.md).
 *
 * I mensili restano fuori di proposito: un promemoria ogni mese per ciascuno
 * diventa una mail che si impara a ignorare.
 */
class Abbonamento extends Model
{
    public const CADENCE_QUARTERLY = 'quarterly';

    public const CADENCE_ANNUAL = 'annual';

    /** Giorni di anticipo del promemoria rispetto al rinnovo. */
    public const REMINDER_DAYS = 7;

    protected $table = 'abbonamenti';

    protected $fillable = [
        'name',
        'supplier_id',
        'cadence',
        'amount',
        'next_renewal_on',
        'last_charged_on',
        'notes',
        'reminded_for',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'next_renewal_on' => 'immutable_date',
        'last_charged_on' => 'immutable_date',
        'reminded_for' => 'immutable_date',
    ];

    /** @return array<string, string> */
    public static function cadenceOptions(): array
    {
        return [
            self::CADENCE_QUARTERLY => 'Trimestrale',
            self::CADENCE_ANNUAL => 'Annuale',
        ];
    }

    public static function monthsFor(string $cadence): int
    {
        return match ($cadence) {
            self::CADENCE_QUARTERLY => 3,
            self::CADENCE_ANNUAL => 12,
            default => throw new \InvalidArgumentException("Cadenza sconosciuta: {$cadence}"),
        };
    }

    /**
     * Primo rinnovo dopo un addebito, portato in avanti finché non cade da oggi
     * in poi: segnare un addebito di un anno fa non deve produrre una scadenza
     * già passata.
     */
    public static function nextRenewalAfter(CarbonInterface $chargedOn, string $cadence, ?CarbonInterface $today = null): CarbonImmutable
    {
        $months = self::monthsFor($cadence);
        $today = CarbonImmutable::parse($today ?? now())->startOfDay();
        $next = CarbonImmutable::parse($chargedOn)->startOfDay()->addMonthsNoOverflow($months);

        while ($next->lt($today)) {
            $next = $next->addMonthsNoOverflow($months);
        }

        return $next;
    }

    public function cadenceLabel(): string
    {
        return self::cadenceOptions()[$this->cadence] ?? $this->cadence;
    }

    /**
     * Se il rinnovo è passato senza che nessuno segnasse il nuovo addebito, lo
     * sposta al giro successivo: il promemoria dell'anno dopo deve partire lo
     * stesso. Restituisce true se ha cambiato la data.
     */
    public function rollForward(?CarbonInterface $today = null): bool
    {
        $today = CarbonImmutable::parse($today ?? now())->startOfDay();

        if ($this->next_renewal_on === null || ! $this->next_renewal_on->lt($today)) {
            return false;
        }

        $months = self::monthsFor($this->cadence);
        $next = $this->next_renewal_on;
        while ($next->lt($today)) {
            $next = $next->addMonthsNoOverflow($months);
        }
        $this->next_renewal_on = $next;

        return true;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
