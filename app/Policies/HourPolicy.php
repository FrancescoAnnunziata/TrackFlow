<?php

namespace App\Policies;

use App\Models\Hour;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class HourPolicy
{
    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Hour $hour)
    {
        return $user->is($hour->user) ? Response::allow() : Response::denyAsNotFound();
    }
}
