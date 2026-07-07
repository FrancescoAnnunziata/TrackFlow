<?php

namespace App\Policies;

use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TicketCommentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TicketComment $comment): Response
    {
        if (! $user->isClient()) {
            return Response::allow();
        }

        // Le note interne non sono visibili ai client.
        if ($comment->internal_note) {
            return Response::denyAsNotFound();
        }

        return $user->belongsToClientId($comment->client_id)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, TicketComment $comment): bool
    {
        return $user->isAdmin() || (int) $comment->user_id === (int) $user->id;
    }

    public function delete(User $user, TicketComment $comment): bool
    {
        return $user->isAdmin() || (int) $comment->user_id === (int) $user->id;
    }
}
