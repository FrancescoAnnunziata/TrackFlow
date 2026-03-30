<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Expense $expense): Response
    {
        return $user->is($expense->user) ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Expense $expense): Response
    {
        return $user->is($expense->user) ? Response::allow() : Response::denyAsNotFound();
    }

    public function delete(User $user, Expense $expense): Response
    {
        return $user->is($expense->user) ? Response::allow() : Response::denyAsNotFound();
    }
}

