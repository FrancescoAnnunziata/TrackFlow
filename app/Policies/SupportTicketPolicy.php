<?php

namespace App\Policies;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SupportTicketPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SupportTicket $ticket): Response
    {
        if (! $user->isClient()) {
            return Response::allow();
        }

        return (int) $ticket->client_id === (int) $user->client_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        // Anche gli utenti client possono aprire ticket.
        return true;
    }

    public function update(User $user, SupportTicket $ticket): Response
    {
        if (! $user->isClient()) {
            return Response::allow();
        }

        // Il client puo' aggiornare solo i ticket che ha aperto.
        return (int) $ticket->opened_by_user_id === (int) $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function delete(User $user, SupportTicket $ticket): bool
    {
        return ! $user->isClient();
    }

    public function restore(User $user, SupportTicket $ticket): bool
    {
        return ! $user->isClient();
    }

    public function forceDelete(User $user, SupportTicket $ticket): bool
    {
        return $user->isAdmin();
    }
}
