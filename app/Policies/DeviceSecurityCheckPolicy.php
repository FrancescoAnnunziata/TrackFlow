<?php

namespace App\Policies;

use App\Models\DeviceSecurityCheck;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DeviceSecurityCheckPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DeviceSecurityCheck $check): Response
    {
        if (! $user->isClient()) {
            return Response::allow();
        }

        return $user->belongsToClientId($check->client_id)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return ! $user->isClient();
    }

    public function update(User $user, DeviceSecurityCheck $check): bool
    {
        return ! $user->isClient();
    }

    public function delete(User $user, DeviceSecurityCheck $check): bool
    {
        return ! $user->isClient();
    }
}
