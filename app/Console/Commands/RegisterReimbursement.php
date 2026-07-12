<?php

namespace App\Console\Commands;

use App\Enums\ReimbursementType;
use App\Models\BankTransaction;
use App\Models\Costo;
use App\Models\PassiveInvoice;
use App\Models\Reconciliation;
use App\Models\Reimbursement;
use App\Models\User;
use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Registra una nota rimborso storica come documento centrale (Reimbursement):
 * crea il rimborso, i costi senza fattura (km/pasti), collega le fatture passive
 * anticipate dal dipendente e riconcilia il/i bonifico/i di rimborso. Il cascade
 * in ReconciliationService marca pagato il rimborso e le passive collegate.
 *
 * Idempotente: se esiste già un rimborso con stessa data e importo, non fa nulla
 * (a meno di --force che lo elimina e ricrea). Eseguibile anche in produzione.
 *
 * Lo spec è un JSON con una nota:
 *   {
 *     "date":"2025-08-31","amount":573.77,
 *     "user_email":"giorgio@g8labs.it",           // opzionale, default: primo admin
 *     "notes":"Rimborso spese Agosto 2025",
 *     "attachment":"reimbursement-attachments/ago-2025.pdf",  // opzionale
 *     "passive_ids":[116],                          // fatture passive anticipate
 *     "costi":[{"conto":"Trasferte","descrizione":"Rimborso km","importo":17.58}],
 *     "bonifico_tx":[37,60]                         // movimenti di rimborso da riconciliare
 *   }
 */
class RegisterReimbursement extends Command
{
    protected $signature = 'finance:register-reimbursement {--spec= : JSON della nota rimborso} {--dry-run} {--force}';

    protected $description = 'Registra una nota rimborso storica (rimborso + costi + passive collegate + riconciliazione bonifico).';

    public function __construct(private readonly ReconciliationService $reconciler)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $spec = json_decode((string) $this->option('spec'), true);
        if (! is_array($spec) || ! isset($spec['date'], $spec['amount'])) {
            $this->error('Spec mancante o non valido: servono almeno "date" e "amount".');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $date = Carbon::parse($spec['date'])->toDateString();
        $amount = round((float) $spec['amount'], 2);

        $existing = Reimbursement::whereDate('date', $date)->where('amount', $amount)->first();
        if ($existing !== null) {
            if (! $this->option('force')) {
                $this->warn("Già presente: rimborso del {$date} da €".number_format($amount, 2).' — salto (usa --force per ricreare).');

                return self::SUCCESS;
            }
            if (! $dry) {
                // Slega passive e costi, poi elimina (nullOnDelete stacca i FK).
                $existing->passiveInvoices()->update(['reimbursement_id' => null, 'payment_status' => PassiveInvoice::STATUS_NOT_PAID]);
                $existing->costi()->delete();
                foreach ($existing->reconciliations()->get() as $r) {
                    $this->reconciler->detach($r);
                }
                $existing->delete();
            }
            $this->line("[force] rimosso rimborso preesistente del {$date}.");
        }

        $user = isset($spec['user_email'])
            ? User::where('email', $spec['user_email'])->first()
            : User::where('role', 'admin')->orderBy('id')->first();
        if ($user === null) {
            $this->error('Utente non trovato per il rimborso.');

            return self::FAILURE;
        }

        $passiveIds = array_map('intval', $spec['passive_ids'] ?? []);
        $costi = $spec['costi'] ?? [];
        $bonificoTx = array_map('intval', $spec['bonifico_tx'] ?? []);

        // Controllo coerenza: passive + costi devono sommare all'importo nota.
        $sumPassive = PassiveInvoice::whereIn('id', $passiveIds)->sum('amount_gross');
        $sumCosti = array_sum(array_map(fn ($c) => (float) $c['importo'], $costi));
        $componentTotal = round((float) $sumPassive + $sumCosti, 2);
        $this->line(sprintf('Nota %s €%s = passive €%s + costi €%s (componenti €%s)',
            $date, number_format($amount, 2), number_format($sumPassive, 2), number_format($sumCosti, 2), number_format($componentTotal, 2)));
        if (abs($componentTotal - $amount) > 0.02) {
            $this->warn('⚠️  I componenti non sommano al totale nota (scarto €'.number_format($amount - $componentTotal, 2).'). Verifica la mappatura.');
        }

        if ($dry) {
            $this->info('[dry-run] nessuna scrittura.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($spec, $user, $date, $amount, $passiveIds, $costi, $bonificoTx): void {
            $reimbursement = Reimbursement::create([
                'user_id' => $user->id,
                'type' => ReimbursementType::Travel,
                'date' => $date,
                'amount' => $amount,
                'notes' => $spec['notes'] ?? null,
                'attachments' => isset($spec['attachment']) ? [$spec['attachment']] : null,
            ]);

            foreach ($costi as $c) {
                Costo::create([
                    'date' => $date,
                    'description' => $c['descrizione'] ?? 'Rimborso',
                    'category' => $c['conto'] ?? null,
                    'amount' => round((float) $c['importo'], 2),
                    'vat_amount' => 0,
                    'reimbursement_id' => $reimbursement->id,
                ]);
            }

            PassiveInvoice::whereIn('id', $passiveIds)->update(['reimbursement_id' => $reimbursement->id]);

            // Riconcilia i bonifici al rimborso (a quote), fino a coprire il totale.
            $remaining = $reimbursement->total();
            foreach ($bonificoTx as $txId) {
                if ($remaining <= 0.01) {
                    break;
                }
                $tx = BankTransaction::find($txId);
                if ($tx === null) {
                    $this->warn("  movimento tx#{$txId} non trovato, salto.");
                    continue;
                }
                $alloc = round(min($tx->unreconciledAmount(), $remaining), 2);
                if ($alloc <= 0.01) {
                    continue;
                }
                $this->reconciler->attach($tx, $reimbursement, $alloc, Reconciliation::BY_MANUAL);
                $remaining = round($remaining - $alloc, 2);
            }

            $reimbursement->refresh();
            $this->info(sprintf('✓ Rimborso #%d creato: stato=%s, %d passive collegate, %d costi, residuo bonifico €%s',
                $reimbursement->id, $reimbursement->status->value, count($passiveIds), count($costi), number_format($remaining, 2)));
        });

        return self::SUCCESS;
    }
}
