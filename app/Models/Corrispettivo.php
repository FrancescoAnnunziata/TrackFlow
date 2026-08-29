<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Incasso di una giornata dell'e-commerce, sulla P.IVA personale (forfettario).
 *
 * Non è un documento fiscale: l'e-commerce (commercio elettronico indiretto) è
 * esonerato da fattura e da corrispettivi telematici, e il forfettario è
 * esonerato dalla tenuta dei registri. Questa tabella esiste per un motivo
 * pratico: la soglia annua del regime si calcola su fatture + vendite online
 * insieme, e nessuno dei due sistemi vede il totale.
 */
class Corrispettivo extends Model
{
    protected $table = 'corrispettivi';

    /** Riga scritta (e riscritta) dal sync automatico da Shopify. */
    public const CHANNEL_SHOPIFY = 'shopify';

    /** Riga inserita a mano: il sync non la tocca. */
    public const CHANNEL_MANUAL = 'manuale';

    protected $fillable = [
        'date',
        'channel',
        'gross',
        'refunds',
        'orders_count',
        'synced_at',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'gross' => 'decimal:2',
        'refunds' => 'decimal:2',
        'orders_count' => 'integer',
        'synced_at' => 'datetime',
    ];

    /**
     * Incassato netto della giornata: è questo che entra nella soglia annua.
     */
    public function getNetAttribute(): float
    {
        return round((float) $this->gross - (float) $this->refunds, 2);
    }

    public static function channelLabel(?string $channel): string
    {
        return match ($channel) {
            self::CHANNEL_SHOPIFY => 'Shopify',
            self::CHANNEL_MANUAL => 'Manuale',
            default => (string) $channel,
        };
    }
}
