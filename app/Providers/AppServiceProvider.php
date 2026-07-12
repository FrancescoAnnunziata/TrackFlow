<?php

namespace App\Providers;

use App\Models\Costo;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Reconciliation;
use App\Models\Reimbursement;
use App\Models\User;
use App\Observers\ReconciliationObserver;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('view-admin', function (User $user) {
            return $user->isAdmin() ? Response::allow() : Response::denyAsNotFound();
        });

        // Alias stabili per i tipi riconciliabili (reconcilable_type in DB).
        Relation::morphMap([
            'invoice' => Invoice::class,
            'passive_invoice' => PassiveInvoice::class,
            'costo' => Costo::class,
            'expense' => Expense::class,
            'reimbursement' => Reimbursement::class,
        ]);

        Reconciliation::observe(ReconciliationObserver::class);
    }
}
