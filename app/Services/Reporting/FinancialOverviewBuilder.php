<?php

namespace App\Services\Reporting;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Costo;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use Illuminate\Support\Carbon;

/**
 * Alimenta la dashboard finanziaria mensile, tenendo separate le due entità:
 *  - G8LABS (SRL, regime ordinario): ricavi, costi, margine e IVA del periodo,
 *    più una foto di liquidità/crediti/debiti;
 *  - Giorgio Giotto (P.IVA forfettaria): incassato e stima tasse/contributi.
 * La separazione si basa sull'invoicing_provider del cliente.
 */
class FinancialOverviewBuilder
{
    private const MESI = ['', 'Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];

    /**
     * ID dei movimenti marcati come giroconto tra conti propri (comando
     * finance:detect-transfers o azione manuale). Sono spostamenti di liquidità,
     * non ricavi né costi, quindi esclusi dal quadro operativo (cassa e "non
     * attribuite") e mostrati a parte.
     *
     * @return array<int, bool> id => true
     */
    private function transferIds(int $year): array
    {
        return BankTransaction::whereYear('booked_at', $year)
            ->whereNotNull('transfer_group_id')
            ->pluck('id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /**
     * Quadro mensile G8LABS: una riga per mese con ricavi (imponibile), costi,
     * margine e saldo IVA (debito sulle vendite − credito sugli acquisti).
     *
     * @return array<int, array{mese: int, label: string, ricavi: float, costi: float, iva_versata: float, margine: float, iva_debito: float, iva_credito: float, iva_saldo: float}>
     */
    public function g8labsMonthly(int $year): array
    {
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = [
                'mese' => $m, 'label' => self::MESI[$m],
                'ricavi' => 0.0, 'costi' => 0.0, 'iva_versata' => 0.0,
                'iva_debito' => 0.0, 'iva_credito' => 0.0,
                'entrate' => 0.0, 'uscite' => 0.0, 'non_attribuite' => 0.0, 'giroconti' => 0.0,
            ];
        }

        // Ricavi + IVA a debito: fatture attive emesse (non bozza) a clienti FiC.
        // Le note di credito attive entrano col segno negativo (stornano ricavo e
        // IVA a debito), come le note di credito passive sul lato costi.
        $invoices = Invoice::query()
            ->whereHas('client', fn ($q) => $q->where('invoicing_provider', Client::PROVIDER_FIC))
            ->where('status', '!=', 'draft')
            ->whereYear('issue_date', $year)
            ->with(['items', 'hours', 'expenses'])
            ->get();
        foreach ($invoices as $inv) {
            $m = Carbon::parse($inv->issue_date)->month;
            $sign = $inv->isCreditNote() ? -1 : 1;
            $months[$m]['ricavi'] += $sign * $inv->taxableAmount();
            $months[$m]['iva_debito'] += $sign * $inv->vatAmount();
        }

        // Costi + IVA a credito: fatture passive (netto), costi e spese non
        // collegate a una passiva (per non contare due volte lo stesso costo).
        // Le note di credito entrano col segno negativo.
        foreach (PassiveInvoice::whereYear('document_date', $year)->get() as $p) {
            $m = Carbon::parse($p->document_date)->month;
            $sign = $p->isCreditNote() ? -1 : 1;
            $months[$m]['costi'] += $sign * (float) $p->amount_net;
            $months[$m]['iva_credito'] += $sign * (float) $p->amount_vat;
        }
        foreach (Costo::whereYear('date', $year)->get() as $c) {
            $m = Carbon::parse($c->date)->month;
            // La liquidazione IVA (F24) è imposta di giro, già contata sul lato
            // vendite: non è costo operativo → voce IVA versata separata, fuori dal
            // margine. Le altre imposte e tasse (ritenute/contributi sul compenso)
            // sono costo vero e restano nel margine come un normale costo.
            if ($c->category === Costo::CATEGORY_VAT) {
                $months[$m]['iva_versata'] += (float) $c->amount;

                continue;
            }
            $months[$m]['costi'] += (float) $c->amount - (float) $c->vat_amount;
            $months[$m]['iva_credito'] += (float) $c->vat_amount;
        }
        foreach (Expense::whereNull('passive_invoice_id')->whereYear('date', $year)->get() as $e) {
            $m = Carbon::parse($e->date)->month;
            $months[$m]['costi'] += (float) $e->amount;
        }

        // Cassa: movimenti bancari reali del mese (entrate/uscite). Le uscite non
        // ancora riconciliate a un documento sono "non attribuite": costi reali
        // usciti dal conto ma non ancora catturati fra i costi documentati (per
        // questo il margine contabile, da soli documenti, può risultare gonfiato).
        // I giroconti tra conti propri sono esclusi dal quadro operativo e mostrati
        // a parte: non sono ricavi né costi, si limitano a spostare liquidità.
        $transfers = $this->transferIds($year);
        foreach (BankTransaction::whereYear('booked_at', $year)->withCount('reconciliations')->get() as $t) {
            $m = Carbon::parse($t->booked_at)->month;
            $amount = (float) $t->amount;

            if (isset($transfers[$t->id])) {
                if ($amount < 0) {
                    $months[$m]['giroconti'] += abs($amount);
                }

                continue;
            }

            if ($amount >= 0) {
                $months[$m]['entrate'] += $amount;
            } else {
                $months[$m]['uscite'] += abs($amount);
                if ((int) $t->reconciliations_count === 0) {
                    $months[$m]['non_attribuite'] += abs($amount);
                }
            }
        }

        foreach ($months as &$row) {
            $row['ricavi'] = round($row['ricavi'], 2);
            $row['costi'] = round($row['costi'], 2);
            $row['iva_versata'] = round($row['iva_versata'], 2);
            $row['margine'] = round($row['ricavi'] - $row['costi'], 2);
            $row['iva_debito'] = round($row['iva_debito'], 2);
            $row['iva_credito'] = round($row['iva_credito'], 2);
            $row['iva_saldo'] = round($row['iva_debito'] - $row['iva_credito'], 2);
            $row['entrate'] = round($row['entrate'], 2);
            $row['uscite'] = round($row['uscite'], 2);
            $row['cassa'] = round($row['entrate'] - $row['uscite'], 2);
            $row['non_attribuite'] = round($row['non_attribuite'], 2);
            $row['giroconti'] = round($row['giroconti'], 2);
        }

        return array_values($months);
    }

    /**
     * Totali annui G8LABS derivati dal quadro mensile.
     *
     * @return array{ricavi: float, costi: float, iva_versata: float, margine: float, iva_saldo: float}
     */
    public function g8labsTotals(int $year): array
    {
        $rows = $this->g8labsMonthly($year);

        return [
            'ricavi' => round(array_sum(array_column($rows, 'ricavi')), 2),
            'costi' => round(array_sum(array_column($rows, 'costi')), 2),
            'iva_versata' => round(array_sum(array_column($rows, 'iva_versata')), 2),
            'margine' => round(array_sum(array_column($rows, 'margine')), 2),
            'iva_saldo' => round(array_sum(array_column($rows, 'iva_saldo')), 2),
            'entrate' => round(array_sum(array_column($rows, 'entrate')), 2),
            'uscite' => round(array_sum(array_column($rows, 'uscite')), 2),
            'cassa' => round(array_sum(array_column($rows, 'cassa')), 2),
            'non_attribuite' => round(array_sum(array_column($rows, 'non_attribuite')), 2),
            'giroconti' => round(array_sum(array_column($rows, 'giroconti')), 2),
        ];
    }

    /**
     * Foto della situazione G8LABS "adesso": liquidità sui conti, crediti verso
     * clienti (fatture emesse non incassate) e debiti verso fornitori (passive
     * non pagate). Importi al netto di quanto già riconciliato.
     *
     * @return array{accounts: array<int, array{name: string, balance: float}>, liquidita: float, crediti: float, debiti: float}
     */
    public function g8labsSnapshot(): array
    {
        $accounts = BankAccount::orderBy('name')->get()
            ->map(fn (BankAccount $a): array => ['name' => $a->name, 'balance' => $a->currentBalance()])
            ->all();

        // Crediti = fatture da incassare al netto degli storni (note di credito
        // collegate) e di quanto già riconciliato. Le note di credito non sono
        // crediti: si limitano a ridurre la fattura stornata.
        $crediti = 0.0;
        $unpaidInvoices = Invoice::query()
            ->whereHas('client', fn ($q) => $q->where('invoicing_provider', Client::PROVIDER_FIC))
            ->whereNotIn('status', ['draft', 'paid'])
            ->where('type', '!=', Invoice::TYPE_CREDIT_NOTE)
            ->with(['items', 'hours', 'expenses', 'reconciliations', 'creditNotes.items'])
            ->get();
        foreach ($unpaidInvoices as $inv) {
            $crediti += max(0, $inv->amountToCollect() - $inv->reconciledAmount());
        }

        $debiti = 0.0;
        $unpaidPassives = PassiveInvoice::where('payment_status', '!=', PassiveInvoice::STATUS_PAID)
            ->where('type', '!=', PassiveInvoice::TYPE_CREDIT_NOTE)
            ->with('reconciliations')
            ->get();
        foreach ($unpaidPassives as $p) {
            $debiti += max(0, $p->total() - $p->reconciledAmount());
        }

        return [
            'accounts' => $accounts,
            'liquidita' => round(array_sum(array_column($accounts, 'balance')), 2),
            'crediti' => round($crediti, 2),
            'debiti' => round($debiti, 2),
        ];
    }

    /**
     * Quadro forfettario (Giorgio Giotto): incassato mese per mese, progresso
     * verso la soglia annua e stima di contributi + imposta sostitutiva. Il
     * forfettario non ha IVA e non deduce i costi: si tassa una quota
     * (coefficiente) del fatturato; i contributi INPS sono deducibili prima
     * dell'imposta.
     *
     * @return array{months: array<int, array{mese: int, label: string, incassato: float}>, incassato_anno: float, limite: float, perc_limite: float, reddito_lordo: float, inps: float, imposta: float, tasse_totali: float, netto: float, coefficiente: float, aliquota: float, inps_rate: float}
     */
    public function forfettario(int $year): array
    {
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = ['mese' => $m, 'label' => self::MESI[$m], 'incassato' => 0.0];
        }

        $invoices = Invoice::query()
            ->whereHas('client', fn ($q) => $q->where('invoicing_provider', Client::PROVIDER_FISCOZEN))
            ->where('status', '!=', 'draft')
            ->whereYear('issue_date', $year)
            ->with(['items', 'hours', 'expenses'])
            ->get();
        foreach ($invoices as $inv) {
            $m = Carbon::parse($inv->issue_date)->month;
            // Forfettario: fattura senza IVA, total() = imponibile.
            $months[$m]['incassato'] += $inv->total();
        }
        foreach ($months as &$row) {
            $row['incassato'] = round($row['incassato'], 2);
        }
        unset($row);

        $incassato = round(array_sum(array_column($months, 'incassato')), 2);

        $coeff = (float) config('forfettario.coefficiente_redditivita');
        $aliquota = (float) config('forfettario.aliquota_imposta');
        $inpsRate = (float) config('forfettario.inps_gestione_separata');
        $limite = (float) config('forfettario.limite_ricavi');

        $redditoLordo = round($incassato * $coeff, 2);
        $inps = round($redditoLordo * $inpsRate, 2);
        $baseImposta = round(max(0, $redditoLordo - $inps), 2);
        $imposta = round($baseImposta * $aliquota, 2);
        $tasse = round($inps + $imposta, 2);

        return [
            'months' => array_values($months),
            'incassato_anno' => $incassato,
            'limite' => $limite,
            'perc_limite' => $limite > 0 ? round($incassato / $limite * 100, 1) : 0.0,
            'reddito_lordo' => $redditoLordo,
            'inps' => $inps,
            'imposta' => $imposta,
            'tasse_totali' => $tasse,
            'netto' => round($incassato - $tasse, 2),
            'coefficiente' => $coeff,
            'aliquota' => $aliquota,
            'inps_rate' => $inpsRate,
        ];
    }
}
