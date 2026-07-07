<?php

namespace App\Policies;

use App\Models\Reimbursement;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ReimbursementPolicy
{
    public function viewAny(User $user): bool
    {
        // Utenti interni (admin/member); i clienti non hanno rimborsi.
        return ! $user->isClient();
    }

    public function view(User $user, Reimbursement $reimbursement): Response
    {
        return $this->ownerOrAdmin($user, $reimbursement)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return ! $user->isClient();
    }

    public function update(User $user, Reimbursement $reimbursement): Response
    {
        return $this->ownerOrAdmin($user, $reimbursement)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function delete(User $user, Reimbursement $reimbursement): Response
    {
        return $this->ownerOrAdmin($user, $reimbursement)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Il proprietario gestisce i propri rimborsi; l'admin li gestisce tutti
     * (es. per approvarli/segnarli come rimborsati).
     */
    private function ownerOrAdmin(User $user, Reimbursement $reimbursement): bool
    {
        return $user->isAdmin() || $user->is($reimbursement->user);
    }
}
