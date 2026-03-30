<?php

namespace App\Policies;

use App\Models\Hour;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class HourPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Hour $hour): Response
    {
        return $user->is($hour->user) ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Hour $hour): Response
    {
        return $user->is($hour->user) ? Response::allow() : Response::denyAsNotFound();
    }

    public function delete(User $user, Hour $hour): Response
    {
        return $user->is($hour->user) ? Response::allow() : Response::denyAsNotFound();
    }
}
