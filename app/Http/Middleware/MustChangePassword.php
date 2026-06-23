<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MustChangePassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Le sessioni autenticate via magic link (clienti senza password) non
        // sono soggette al cambio password obbligatorio.
        if ($user?->must_change_password
            && ! $request->session()->get('quote_magic_login')
            && ! $request->routeIs('filament.app.pages.change-password')) {
            return redirect()->route('filament.app.pages.change-password');
        }

        return $next($request);
    }
}
