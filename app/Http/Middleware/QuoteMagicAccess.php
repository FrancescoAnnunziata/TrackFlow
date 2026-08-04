<?php

namespace App\Http\Middleware;

use App\Models\Quote;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accesso al documento del preventivo. Il referente del cliente non ha (e non
 * deve avere) una password: per lui la firma dell'URL ricevuto via email vale
 * come autenticazione, finché non scade. Chi arriva senza sessione e senza un
 * link valido vede una pagina che glielo spiega, non il form di login.
 */
class QuoteMagicAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        // Solo se non c'è già una sessione: chi è loggato resta se stesso, e
        // sarà il controller a dire se quel preventivo è affar suo.
        if (! $request->user() && $request->hasValidSignature()) {
            $this->loginSignedUser($request);
        }

        if (! $request->user()) {
            return response()->view('quotes.link-non-valido', [], 403);
        }

        return $next($request);
    }

    /**
     * Autentica il referente indicato nel link, se è davvero un referente del
     * cliente a cui il preventivo è intestato.
     */
    private function loginSignedUser(Request $request): void
    {
        $quote = $request->route('quote');
        $user = User::find($request->integer('user'));

        if (! $quote instanceof Quote || ! $user?->isClient() || ! $user->belongsToClientId($quote->client_id)) {
            return;
        }

        Auth::login($user);

        // Nuova sessione appena autenticato: il link gira via email e potrebbe
        // essere aperto su un dispositivo con una sessione già iniziata.
        $request->session()->regenerate();
        $request->setUserResolver(fn () => $user);

        // Il link sostituisce la password: salta il gate "cambio password
        // obbligatorio" solo per questa sessione (vedi MustChangePassword).
        $request->session()->put('quote_magic_login', true);
    }
}
