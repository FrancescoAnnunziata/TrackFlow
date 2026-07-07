<?php

namespace App\Policies;

use App\Models\SecurityFinding;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SecurityFindingPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SecurityFinding $finding): Response
    {
        if (! $user->isClient()) {
            return Response::allow();
        }

        return $user->belongsToClientId($finding->client_id)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return ! $user->isClient();
    }

    public function update(User $user, SecurityFinding $finding): bool
    {
        return ! $user->isClient();
    }

    public function delete(User $user, SecurityFinding $finding): bool
    {
        return ! $user->isClient();
    }
}
