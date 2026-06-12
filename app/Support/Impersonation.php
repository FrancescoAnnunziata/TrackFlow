<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class Impersonation
{
    /**
     * Session key holding the id of the original (admin) user while impersonating.
     */
    public const SESSION_KEY = 'impersonator_id';

    /**
     * Log in as the given user, remembering who started the impersonation.
     */
    public static function start(User $target): void
    {
        // Nested impersonation is not supported: keep the very first impersonator.
        if (static::isImpersonating()) {
            return;
        }

        session()->put(self::SESSION_KEY, Auth::id());

        Auth::login($target);

        static::forgetSessionPasswordHash();
    }

    /**
     * Restore the original user and end the impersonation. Returns the impersonator.
     */
    public static function stop(): ?User
    {
        if (! static::isImpersonating()) {
            return null;
        }

        $impersonator = User::find(session()->pull(self::SESSION_KEY));

        if (! $impersonator) {
            Auth::logout();

            return null;
        }

        Auth::login($impersonator);

        static::forgetSessionPasswordHash();

        return $impersonator;
    }

    public static function isImpersonating(): bool
    {
        return session()->has(self::SESSION_KEY);
    }

    /**
     * The original (admin) user currently impersonating, if any.
     */
    public static function impersonator(): ?User
    {
        if (! static::isImpersonating()) {
            return null;
        }

        return User::find(session()->get(self::SESSION_KEY));
    }

    /**
     * Drop the password hash kept by AuthenticateSession. Without this the
     * middleware would log the session out on the next request, because the
     * stored hash no longer matches the swapped user. It is transparently
     * re-stored for the current user on the following request.
     */
    protected static function forgetSessionPasswordHash(): void
    {
        session()->forget('password_hash_' . Auth::getDefaultDriver());
    }
}