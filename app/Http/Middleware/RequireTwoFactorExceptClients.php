<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Come EnsureMultiFactorAuthenticationIsEnabled di Filament, ma lascia passare
 * i clienti.
 *
 * I due fattori restano obbligatori per chi lavora in TrackFlow (admin, membri,
 * commercialista). I clienti no: entrano nel portale saltuariamente, spesso da
 * un magic link, e non hanno un help desk a cui rivolgersi se perdono il
 * telefono — l'unico rimedio sarebbe chiamare noi.
 *
 * Serve un middleware al posto di una closure su isRequired() perche' quella
 * viene valutata quando le route vengono registrate (e messe in cache), non a
 * ogni richiesta: li' l'utente non esiste ancora.
 */
class RequireTwoFactorExceptClients
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        // Utente assente: se ne occupa Authenticate, che gira prima di questo.
        if (! $user || $user->isClient()) {
            return $next($request);
        }

        foreach (Filament::getMultiFactorAuthenticationProviders() as $provider) {
            if ($provider->isEnabled($user)) {
                return $next($request);
            }
        }

        return redirect()->guest(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
    }
}
