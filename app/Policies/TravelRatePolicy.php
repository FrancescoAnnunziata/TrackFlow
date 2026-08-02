<?php

namespace App\Policies;

use App\Models\TravelRate;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TravelRatePolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isClient();
    }

    public function view(User $user, TravelRate $travelRate): Response
    {
        return $this->ownerOrAdmin($user, $travelRate)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return ! $user->isClient();
    }

    public function update(User $user, TravelRate $travelRate): Response
    {
        return $this->ownerOrAdmin($user, $travelRate)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function delete(User $user, TravelRate $travelRate): Response
    {
        return $this->ownerOrAdmin($user, $travelRate)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Ognuno gestisce la propria tabella trasferte; l'admin le gestisce tutte.
     */
    private function ownerOrAdmin(User $user, TravelRate $travelRate): bool
    {
        return $user->isAdmin() || $user->is($travelRate->user);
    }
}
