<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Client $client): Response
    {
        return Response::allow();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Client $client): Response
    {
        return Response::allow();
    }

    public function delete(User $user, Client $client): Response
    {
        return Response::allow();
    }
}


