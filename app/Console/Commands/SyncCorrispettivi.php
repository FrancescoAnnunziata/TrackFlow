<?php

namespace App\Console\Commands;

use App\Services\Shopify\CorrispettiviSync;
use App\Services\Shopify\ShopifyClient;
use App\Services\Shopify\ShopifyException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncCorrispettivi extends Command
{
    protected $signature = 'corrispettivi:sync
        {--days= : Quanti giorni indietro risincronizzare (default: services.shopify.resync_days)}
        {--from= : Data iniziale (Y-m-d), ha la precedenza su --days}
        {--to= : Data finale (Y-m-d), default oggi}';

    protected $description = 'Scarica da Shopify gli incassi giornalieri dell\'e-commerce (P.IVA personale).';

    public function handle(): int
    {
        $shopify = ShopifyClient::fromConfig();

        if (! $shopify->isConfigured()) {
            $this->warn('Shopify non è configurato (SHOPIFY_SHOP_DOMAIN / SHOPIFY_ADMIN_API_TOKEN): salto.');

            // Non è un errore: su una macchina dove nessuno l'ha configurato lo
            // scheduler non deve riempire i log di fallimenti.
            return self::SUCCESS;
        }

        $to = $this->option('to')
            ? Carbon::parse($this->option('to'))
            : Carbon::today();

        $from = $this->option('from')
            ? Carbon::parse($this->option('from'))
            : $to->copy()->subDays($this->resyncDays());

        if ($from->greaterThan($to)) {
            $this->error('L\'intervallo è rovesciato: --from viene dopo --to.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Sincronizzo gli incassi Shopify dal %s al %s...',
            $from->format('d/m/Y'),
            $to->format('d/m/Y'),
        ));

        try {
            $righe = (new CorrispettiviSync($shopify))->sync($from, $to);
        } catch (ShopifyException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($righe->isEmpty()) {
            $this->info('Nessun incasso nel periodo.');

            return self::SUCCESS;
        }

        $this->table(
            ['Giorno', 'Ordini', 'Lordo', 'Resi', 'Netto'],
            $righe->map(fn ($riga) => [
                $riga->date->format('d/m/Y'),
                $riga->orders_count,
                number_format((float) $riga->gross, 2, ',', '.'),
                number_format((float) $riga->refunds, 2, ',', '.'),
                number_format($riga->net, 2, ',', '.'),
            ])->all(),
        );

        $this->info(sprintf(
            '%d giorni aggiornati · netto del periodo € %s',
            $righe->count(),
            number_format($righe->sum(fn ($riga) => $riga->net), 2, ',', '.'),
        ));

        return self::SUCCESS;
    }

    private function resyncDays(): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('services.shopify.resync_days', 14);

        return max(0, $days);
    }
}
