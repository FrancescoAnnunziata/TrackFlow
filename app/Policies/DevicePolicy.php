<?php

namespace App\Policies;

use App\Models\Device;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DevicePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Device $device): Response
    {
        if ($user->isAdmin() || ! $user->isClient()) {
            return Response::allow();
        }

        return (int) $device->client_id === (int) $user->client_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return ! $user->isClient();
    }

    public function update(User $user, Device $device): bool
    {
        return ! $user->isClient();
    }

    public function delete(User $user, Device $device): bool
    {
        return ! $user->isClient();
    }

    public function restore(User $user, Device $device): bool
    {
        return ! $user->isClient();
    }

    public function forceDelete(User $user, Device $device): bool
    {
        return $user->isAdmin();
    }
}
