<?php

namespace App\Providers;

use App\Assistant\ClaudeChatClient;
use App\Assistant\Contracts\ChatClient;
use App\Http\Middleware\ReauthenticateWeekly;
use App\Listeners\RecordLastLogin;
use App\Models\Costo;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Reconciliation;
use App\Models\Reimbursement;
use App\Models\User;
use App\Observers\ReconciliationObserver;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // L'assistente AI parla con Claude tramite questo contratto (fake nei test).
        $this->app->bind(ChatClient::class, ClaudeChatClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // «Ricordami» di serie dura più di un anno: su un pannello con i conti
        // dentro è troppo, e renderebbe i due fattori una formalità del primo
        // giorno. Dura quanto la finestra di riaccesso: una settimana.
        $guard = Auth::guard();

        if ($guard instanceof SessionGuard) {
            $guard->setRememberDuration(ReauthenticateWeekly::DAYS * 24 * 60);
        }

        // Registrato a mano e non per scoperta automatica: da questa data
        // dipende quando i due fattori tornano a chiedersi, e una convenzione
        // silenziosa è il modo in cui si spegne senza accorgersene.
        Event::listen(Login::class, RecordLastLogin::class);

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
