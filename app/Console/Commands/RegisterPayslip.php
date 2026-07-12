<?php

namespace App\Console\Commands;

use App\Models\BankTransaction;
use App\Models\Costo;
use App\Models\Reconciliation;
use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Registra una busta paga come costo (compenso amministratore/collaboratore) e
 * chiude il bonifico dello stipendio: crea un Costo col netto in busta, allega
 * il PDF della busta e riconcilia il bonifico. Contributi e ritenute (F24) sono
 * un'uscita separata, non inclusa qui.
 *
 * Idempotente: salta se esiste già un Costo compenso stessa data e importo (a
 * meno di --force). Eseguibile anche in produzione.
 *
 * Spec JSON:
 *   {"date":"2026-01-31","amount":1500.00,"conto":"Collaboratori",
 *    "descrizione":"Compenso amministratore Gennaio 2026",
 *    "attachment":"payslip-attachments/2026-01-giotto.pdf","bonifico_tx":222}
 */
class RegisterPayslip extends Command
{
    protected $signature = 'finance:register-payslip {--spec=} {--dry-run} {--force}';

    protected $description = 'Registra una busta paga come costo compenso e riconcilia il bonifico dello stipendio.';

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

        $date = Carbon::parse($spec['date'])->toDateString();
        $amount = round((float) $spec['amount'], 2);
        $conto = $spec['conto'] ?? 'Collaboratori';
        $descrizione = $spec['descrizione'] ?? 'Compenso amministratore';

        $existing = Costo::whereDate('date', $date)->where('amount', $amount)->where('category', $conto)->first();
        if ($existing !== null && ! $this->option('force')) {
            $this->warn("Già presente: compenso del {$date} da €".number_format($amount, 2).' — salto (--force per ricreare).');

            return self::SUCCESS;
        }

        $this->line("Busta {$date}: costo €".number_format($amount, 2)." ({$conto})".(isset($spec['attachment']) ? ' + PDF' : ' [PDF mancante]'));
        if ($this->option('dry-run')) {
            $this->info('[dry-run] nessuna scrittura.');

            return self::SUCCESS;
        }

        if ($existing !== null) {
            foreach ($existing->reconciliations()->get() as $r) {
                $this->reconciler->detach($r);
            }
            $existing->delete();
        }

        $costo = Costo::create([
            'date' => $date,
            'description' => $descrizione,
            'category' => $conto,
            'amount' => $amount,
            'vat_amount' => 0,
            'notes' => $spec['notes'] ?? null,
            'attachments' => isset($spec['attachment']) ? [$spec['attachment']] : null,
        ]);

        $residuo = 'n/d';
        if (isset($spec['bonifico_tx'])) {
            $tx = BankTransaction::find((int) $spec['bonifico_tx']);
            if ($tx === null) {
                $this->warn('  movimento bonifico non trovato: costo creato ma non riconciliato.');
            } else {
                $alloc = round(min($tx->unreconciledAmount(), $costo->total()), 2);
                if ($alloc > 0.01) {
                    $this->reconciler->attach($tx, $costo, $alloc, Reconciliation::BY_MANUAL);
                }
                $residuo = number_format($tx->fresh()->unreconciledAmount(), 2);
            }
        }

        $this->info("✓ Costo compenso #{$costo->id} creato · bonifico residuo €{$residuo}");

        return self::SUCCESS;
    }
}
