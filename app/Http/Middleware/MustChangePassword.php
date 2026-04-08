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

        if ($user?->must_change_password && ! $request->routeIs('filament.app.pages.change-password')) {
            return redirect()->route('filament.app.pages.change-password');
        }

        return $next($request);
    }
}
