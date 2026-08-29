<?php

use App\Models\FicCredential;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Solleciti preventivi inviati e non ancora accettati/rifiutati (a 5 e 10 giorni).
Schedule::command('quotes:send-reminders')->dailyAt('09:00')->timezone('Europe/Rome');

// Promemoria giornaliero "segna le ore" agli admin/member che lo hanno attivato.
Schedule::command('hours:send-reminders')->weekdays()->at('18:00')->timezone('Europe/Rome');

// Incassi giornalieri dell'e-commerce Shopify (P.IVA personale): tengono
// aggiornato il progresso verso la soglia del forfettario. Gira a notte fonda
// per trovare il giorno prima già chiuso, e risincronizza anche i giorni
// recenti perché i resi arrivano dopo l'ordine.
Schedule::command('corrispettivi:sync')->dailyAt('02:30')->timezone('Europe/Rome');

// Fatture passive da Fatture in Cloud, ogni tre ore: l'archivio dei costi si
// tiene aggiornato da solo invece di dipendere da chi si ricorda di premere il
// pulsante. Una pagina sola per tipo (FIC ordina dal più recente) basta e
// avanza per un incrementale così frequente, e --create-suppliers evita che una
// fattura di un fornitore nuovo venga scartata in silenzio.
// Il `when` tiene fuori gli ambienti dove FIC non è collegato: lì il comando
// fallirebbe ogni tre ore riempiendo i log di errori che non sono errori.
Schedule::command('fic:import-passive-invoices --pages=1 --create-suppliers')
    ->everyThreeHours()
    ->timezone('Europe/Rome')
    ->when(fn (): bool => FicCredential::isConnected())
    ->withoutOverlapping();
