<?php

namespace App\Policies;

use App\Models\DeviceMaintenance;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DeviceMaintenancePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DeviceMaintenance $maintenance): Response
    {
        if (! $user->isClient()) {
            return Response::allow();
        }

        return (int) $maintenance->client_id === (int) $user->client_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return ! $user->isClient();
    }

    public function update(User $user, DeviceMaintenance $maintenance): bool
    {
        return ! $user->isClient();
    }

    public function delete(User $user, DeviceMaintenance $maintenance): bool
    {
        return ! $user->isClient();
    }
}
