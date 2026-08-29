<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Il manuale operativo (pagina Guida) in testo semplice, per darlo in pasto
 * all'assistente AI.
 *
 * Serve perché l'assistente, senza, risponde da contabile generico: consiglia
 * quello che si farebbe di solito, non quello che si fa QUI. Le nostre
 * procedure hanno regole che non si deducono dai dati — le fatture estere si
 * caricano a mano prima di riconciliare, Alsea è trimestrale anticipata con le
 * spese del periodo precedente, i clienti Fiscozen non si inviano da TrackFlow.
 * Dandogli il manuale, chi chiede aiuto riceve la stessa risposta che leggerebbe
 * nella Guida.
 *
 * Il testo arriva dalla stessa vista che si vede a schermo: una copia sola, e
 * quando il manuale cambia cambia anche quello che sa l'assistente.
 */
class ManualeOperativo
{
    private const VIEW = 'filament.manuale-contenuto';

    /** La conversione HTML→testo è deterministica: si rifà solo a deploy nuovo. */
    private const CACHE_KEY = 'manuale-operativo-testo';

    /**
     * Tetto di sicurezza: oggi il manuale sta sui 24.000 caratteri, quindi c'è
     * abbondante margine. Serve perché il testo finisce in ogni chiamata
     * all'assistente: se un giorno raddoppiasse, meglio troncarlo che far
     * crescere in silenzio il costo di ogni conversazione.
     */
    private const MAX_CHARS = 40000;

    public function testo(): string
    {
        return Cache::rememberForever(self::CACHE_KEY.'.'.$this->versione(), fn (): string => $this->rendi());
    }

    /**
     * Il manuale come blocco da appendere al system prompt, o stringa vuota se
     * per qualsiasi motivo non è leggibile: l'assistente deve continuare a
     * funzionare anche senza.
     */
    public function perIlPrompt(): string
    {
        $testo = trim($this->testo());

        if ($testo === '') {
            return '';
        }

        return <<<PROMPT
        MANUALE OPERATIVO DI TRACKFLOW (la pagina "Guida" dell'app)

        Qui sotto c'è il manuale che l'azienda ha scritto per chi gestisce la fatturazione. Quando qualcuno ti chiede
        come si fa una cosa, rispondi seguendo QUESTE procedure, non la prassi contabile generica: cita il passo del
        manuale e dove si trova nell'app. Se il manuale non copre il caso, dillo apertamente invece di inventare una
        procedura.

        --- INIZIO MANUALE ---
        {$testo}
        --- FINE MANUALE ---
        PROMPT;
    }

    /**
     * Impronta della vista: cambia a ogni modifica del manuale, così la cache
     * si rinnova da sola senza doversi ricordare di svuotarla.
     */
    private function versione(): string
    {
        $percorso = $this->percorso();

        return $percorso !== null ? substr(md5_file($percorso) ?: '', 0, 12) : 'assente';
    }

    private function percorso(): ?string
    {
        $percorso = resource_path('views/'.str_replace('.', '/', self::VIEW).'.blade.php');

        return is_file($percorso) ? $percorso : null;
    }

    private function rendi(): string
    {
        if ($this->percorso() === null) {
            return '';
        }

        $html = view(self::VIEW)->render();

        // I titoli e le voci di elenco diventano righe: senza, il testo si
        // appiattisce in un unico paragrafo illeggibile anche per il modello.
        $html = preg_replace('#</(p|li|div|h[1-6]|tr|section)>#i', "\n", $html) ?? $html;
        $html = preg_replace('#<li[^>]*>#i', '- ', $html) ?? $html;
        $html = preg_replace('#</t[dh]>#i', ' | ', $html) ?? $html;

        $testo = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Spazi orizzontali compattati, righe vuote ridotte a una sola.
        $testo = preg_replace('/[ \t]+/', ' ', $testo) ?? $testo;
        $testo = preg_replace('/ *\n */', "\n", $testo) ?? $testo;
        $testo = preg_replace('/\n{3,}/', "\n\n", $testo) ?? $testo;

        return mb_substr(trim($testo), 0, self::MAX_CHARS);
    }
}
