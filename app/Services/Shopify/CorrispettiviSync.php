<?php

namespace App\Services\Shopify;

use App\Models\Corrispettivo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Porta gli incassi Shopify dentro `corrispettivi`, un giorno per riga.
 *
 * È volutamente riscrivibile: ogni esecuzione ricalcola da zero i giorni
 * dell'intervallo e sovrascrive le righe `shopify`. Così un reso arrivato dopo,
 * o un ordine annullato, correggono il giorno a cui appartengono invece di
 * lasciare in giro un totale che non torna più.
 */
class CorrispettiviSync
{
    public function __construct(private readonly ShopifyClient $shopify) {}

    public static function make(): self
    {
        return new self(ShopifyClient::fromConfig());
    }

    /**
     * Sincronizza l'intervallo indicato (estremi inclusi) e ritorna le righe
     * toccate, ordinate per data.
     *
     * @return Collection<int, Corrispettivo>
     */
    public function sync(Carbon $from, Carbon $to): Collection
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        $orders = $this->shopify->paidOrdersBetween($from, $to);

        $perDay = [];
        foreach ($orders as $order) {
            $day = $order['processed_at']->toDateString();

            $perDay[$day] ??= ['gross' => 0.0, 'refunds' => 0.0, 'orders' => 0];
            $perDay[$day]['gross'] += $order['gross'];
            $perDay[$day]['refunds'] += $order['refunded'];
            $perDay[$day]['orders']++;
        }

        $touched = collect();

        for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day->addDay()) {
            $key = $day->toDateString();
            $totals = $perDay[$key] ?? null;

            $existing = Corrispettivo::query()
                ->whereDate('date', $key)
                ->where('channel', Corrispettivo::CHANNEL_SHOPIFY)
                ->first();

            // Giorno senza incassi e senza riga: non creiamo righe a zero, ne
            // avremmo centinaia. Se invece la riga c'è già va azzerata, perché
            // significa che quello che c'era è stato annullato.
            if ($totals === null && $existing === null) {
                continue;
            }

            $values = [
                'gross' => round($totals['gross'] ?? 0, 2),
                'refunds' => round($totals['refunds'] ?? 0, 2),
                'orders_count' => $totals['orders'] ?? 0,
                'synced_at' => now(),
            ];

            if ($existing === null) {
                $existing = Corrispettivo::create($values + [
                    'date' => $key,
                    'channel' => Corrispettivo::CHANNEL_SHOPIFY,
                ]);
            } else {
                $existing->update($values);
            }

            $touched->push($existing);
        }

        return $touched->sortBy('date')->values();
    }
}
