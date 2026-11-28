<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Carica gli abbonamenti trimestrali e annuali già in corso al 28/11/2026,
 * ricostruiti dai movimenti bancari (BCC e Vivid) e dalle fatture passive.
 * Da qui in avanti li segna Paola dai movimenti o dalle fatture.
 *
 * Gli ultimi quattro sono abbonamenti disdetti o da verificare: stanno in
 * elenco apposta, perché la mail della settimana prima ricordi di controllare
 * che l'addebito non arrivi.
 *
 * Nei test non carica niente: l'elenco è un dato della produzione, non uno
 * stato di partenza su cui scrivere asserzioni.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || DB::table('abbonamenti')->exists()) {
            return;
        }

        $verifica = 'controllare che l\'addebito non arrivi.';

        // [nome, fornitore (LIKE), importo, ultimo addebito, prossimo rinnovo, note]
        $rows = [
            ['Fatture in Cloud', '%TeamSystem%', 308.66, '2026-08-02', '2027-08-02', null],
            ['Wispr Flow', null, 144.00, '2026-08-28', '2027-08-28', null],
            ['Webflow', '%Webflow%', 196.48, '2025-11-15', '2027-11-15', 'Ultimo addebito trovato il 15/11/2025: il rinnovo di novembre 2026 non risulta ancora fra i movimenti.'],
            ['Vimeo', '%VIMEO%', 117.12, '2025-12-24', '2026-12-24', null],
            ['reMarkable Connect', null, 29.90, '2026-01-26', '2027-01-26', null],
            ['Register.it (rinnovo di gennaio)', null, 66.06, '2026-01-20', '2027-01-20', 'Dominio da indicare nel nome.'],
            ['Register.it (rinnovo di agosto)', null, 66.06, '2026-08-27', '2027-08-27', 'Dominio da indicare nel nome.'],
            ['Codice LEI', '%InfoCamere%', 79.30, '2026-10-31', '2027-10-31', null],
            ['Coolplugins (plugin WordPress)', null, 31.69, '2026-04-07', '2027-04-07', 'Cadenza annuale presunta: verificare sul sito del fornitore.'],

            ['SiteGround — clubberly.com (dominio, protezione ID, backup)', '%SiteGround%', 103.27, '2025-09-02', '2027-09-02', null],
            ['SiteGround — dominio fiduciaeliberta.com', '%SiteGround%', 19.99, '2025-09-27', '2027-09-27', null],
            ['SiteGround — dominio dolcitaliaexchange.it', '%SiteGround%', 12.99, '2026-03-19', '2027-03-19', null],
            ['SiteGround — dominio fedespedi-notifications.it', '%SiteGround%', 12.99, '2026-03-24', '2027-03-24', null],
            ['SiteGround — dominio notifications-g8labs.it', '%SiteGround%', 12.99, '2026-03-30', '2027-03-30', null],
            ['SiteGround — domini sailwms.com e sailwms.it', '%SiteGround%', 42.68, '2026-04-03', '2027-04-03', null],
            ['SiteGround — dominio interclubcastenedolo.it', '%SiteGround%', 12.99, '2026-04-04', '2027-04-04', null],
            ['SiteGround — dominio freightmiddle.com', '%SiteGround%', 17.99, '2026-04-11', '2027-04-11', null],
            ['SiteGround — Premium CDN dolcitaliaexchange.it', '%SiteGround%', 119.88, '2026-04-29', '2027-04-29', null],
            ['SiteGround — dominio rotaryzerotrenta.it', '%SiteGround%', 12.99, '2026-05-20', '2027-05-20', null],
            ['SiteGround — hosting GoGeek e dominio giorgiogiotto.it', '%SiteGround%', 492.87, '2026-06-19', '2027-06-19', null],
            ['SiteGround — domini osagent.eu e osagent.it', '%SiteGround%', 25.98, '2026-07-01', '2027-07-01', null],
            ['SiteGround — Premium Backup e Site Scanner dolcitaliaexchange.it', '%SiteGround%', 159.78, '2026-07-06', '2027-07-06', null],

            ['AnyDesk', '%AnyDesk%', 346.80, '2026-10-03', '2027-10-03', 'Disdetto il 28/11/2026: '.$verifica],
            ['CalendarBridge', '%CalendarBridge%', 84.14, '2026-03-14', '2027-03-14', 'Messo in pausa a novembre 2026: '.$verifica],
            ['Microsoft', '%Microsoft%', 51.24, '2026-09-27', '2027-09-27', 'Dovrebbe essere già disdetto: '.$verifica],
            ['VEED', '%VEED%', 120.00, '2026-02-12', '2027-02-12', 'Dovrebbe essere già disdetto: '.$verifica],
        ];

        $now = now();

        foreach ($rows as [$name, $supplier, $amount, $last, $next, $notes]) {
            DB::table('abbonamenti')->insert([
                'name' => $name,
                'supplier_id' => $supplier ? DB::table('suppliers')->where('name', 'like', $supplier)->orderBy('id')->value('id') : null,
                'cadence' => 'annual',
                'amount' => $amount,
                'last_charged_on' => $last,
                'next_renewal_on' => $next,
                'notes' => $notes,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // I dati restano: la tabella la toglie la migrazione che la crea.
    }
};
