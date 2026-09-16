<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ogni quanto si rifà l'accesso per intero.
 *
 * La sessione da sola non basta a rispondere: si rinnova a ogni richiesta, e
 * chi usa TrackFlow tutti i giorni non se la vedrebbe scadere mai. Il cookie
 * «Ricordami» nemmeno: riporta dentro senza chiedere niente.
 *
 * Qui il conto parte dall'ultimo accesso fatto davvero (App\Listeners\RecordLastLogin),
 * non dalla sessione corrente: passata la settimana si torna alla schermata di
 * login, password e codice a due fattori compresi, anche su un dispositivo
 * ricordato. Il logout azzera anche il token di «Ricordami», quindi il cookie
 * non lo riporta dentro un istante dopo.
 *
 * I clienti sono fuori: entrano dal magic link, che ha già una scadenza sua
 * (Quote::MAGIC_LINK_DAYS), e non hanno una password da ridigitare.
 */
class ReauthenticateWeekly
{
    /**
     * Quanto dura un accesso prima che vada rifatto per intero. Cambiando questo
     * numero, cambia anche la durata del cookie «Ricordami»: la imposta
     * AppServiceProvider leggendo di qui.
     */
    public const DAYS = 7;

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Filament::auth();
        $user = $guard->user();

        // Utente assente: se ne occupa Authenticate, che gira prima di questo.
        if (! $user || $user->isClient()) {
            return $next($request);
        }

        // Accessi precedenti a questa modifica: nessuna data da cui contare.
        // Il primo passaggio la scrive, e la settimana parte da adesso.
        if ($user->last_login_at === null) {
            $user->forceFill(['last_login_at' => now()])->saveQuietly();

            return $next($request);
        }

        if ($user->last_login_at->addDays(self::DAYS)->isFuture()) {
            return $next($request);
        }

        $guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->guest(Filament::getLoginUrl())
            ->with('status', 'Sono passati '.self::DAYS.' giorni dall\'ultimo accesso: rifallo per continuare.');
    }
}
