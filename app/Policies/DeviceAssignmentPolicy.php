<?php

namespace App\Policies;

use App\Models\DeviceAssignment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DeviceAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DeviceAssignment $assignment): Response
    {
        if (! $user->isClient()) {
            return Response::allow();
        }

        return $user->belongsToClientId($assignment->client_id)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return ! $user->isClient();
    }

    public function update(User $user, DeviceAssignment $assignment): bool
    {
        return ! $user->isClient();
    }

    public function delete(User $user, DeviceAssignment $assignment): bool
    {
        return ! $user->isClient();
    }
}
