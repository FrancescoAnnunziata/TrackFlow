<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Recupera dallo storico delle fatture attive le spese riaddebitate ai clienti,
 * per ricrearle come Expense. Individua le righe "rimborso spese" (art. 15 o per
 * nome) e, dove il testo lo consente, le scompone nelle singole voci; altrimenti
 * tiene un'unica spesa con l'importo della riga. Nessuna scrittura: produce solo
 * la proposta, che il comando mostra in dry-run e applica su conferma.
 */
class InvoiceExpenseExtractor
{
    /** Righe che rappresentano un riaddebito di spese (oltre a tutte le art.15). */
    private const RECHARGE_NAME = '/rimbors|spes[ae]|trasfert|pranzo|vitto|costi vivi|acquisto per conto/i';

    /** Tolleranza fra somma delle voci e totale riga per accettare l\'itemizzazione. */
    private const EPSILON = 0.02;

    /**
     * Proposta di spese da creare, una entry per fattura idonea.
     *
     * @return Collection<int, array{invoice: Invoice, expenses: array<int, array{date: string, amount: float, conto: ?string, notes: string, itemized: bool}>}>
     */
    public function proposals(): Collection
    {
        return Invoice::query()
            ->whereHas('client', fn ($q) => $q->where('invoicing_provider', 'fatture_in_cloud'))
            // Idempotenza: salta le fatture che hanno già spese collegate
            // (le recenti registrate a mano o un'esecuzione precedente).
            ->whereDoesntHave('expenses')
            ->with(['items', 'client'])
            ->orderBy('issue_date')
            ->get()
            ->map(fn (Invoice $invoice): array => [
                'invoice' => $invoice,
                'expenses' => $this->expensesForInvoice($invoice),
            ])
            ->filter(fn (array $p): bool => $p['expenses'] !== [])
            ->values();
    }

    /**
     * @return array<int, array{date: string, amount: float, conto: ?string, notes: string, itemized: bool}>
     */
    private function expensesForInvoice(Invoice $invoice): array
    {
        $date = optional($invoice->issue_date)->toDateString() ?? now()->toDateString();
        $out = [];

        foreach ($invoice->items as $item) {
            if (! $this->isRecharge($item)) {
                continue;
            }

            $total = round((float) $item->qty * (float) $item->net_price, 2);
            if ($total <= 0) {
                continue;
            }

            // Il dettaglio sta nella descrizione della riga; se rimanda alle note
            // ("Vedi note") o è vuota, si prova con le note della fattura.
            $detailText = (string) $item->description;
            if ($this->isPointerToNotes($detailText)) {
                $detailText = strip_tags((string) $invoice->notes);
            }

            $parts = $this->parseDetail($detailText);
            $sum = round(array_sum(array_column($parts, 'amount')), 2);

            if (count($parts) >= 2 && abs($sum - $total) <= self::EPSILON) {
                foreach ($parts as $part) {
                    $out[] = [
                        'date' => $date,
                        'amount' => $part['amount'],
                        'conto' => $this->contoFromText($part['label']) ?? $this->contoFromText($item->name),
                        'notes' => $this->buildNote($invoice, $item, $part['label']),
                        'itemized' => true,
                    ];
                }

                continue;
            }

            // Fallback: un'unica spesa con l'importo della riga.
            $out[] = [
                'date' => $date,
                'amount' => $total,
                'conto' => $this->contoFromText($item->name),
                'notes' => $this->buildNote($invoice, $item, null),
                'itemized' => false,
            ];
        }

        return $out;
    }

    private function isRecharge(InvoiceItem $item): bool
    {
        if ($item->vat_kind === InvoiceItem::VAT_ART15) {
            return true;
        }

        return (bool) preg_match(self::RECHARGE_NAME, (string) $item->name);
    }

    private function isPointerToNotes(string $text): bool
    {
        $t = Str::lower(trim(strip_tags($text)));

        return $t === '' || str_contains($t, 'vedi note') || str_contains($t, 'in nota');
    }

    /**
     * Estrae le voci "etichetta + importo" da un blocco di testo libero. Per ogni
     * riga toglie le date (che contengono numeri) e prende l'ultimo importo
     * monetario; l'etichetta è il resto della riga. Ritorna [] se non trova nulla.
     *
     * @return array<int, array{label: string, amount: float}>
     */
    public function parseDetail(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", strip_tags($text));
        $parts = [];

        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Via le date (gg/mm[/aaaa] o gg.mm[.aaaa]), che contengono numeri.
            $clean = preg_replace('#\b\d{1,2}[/.]\d{1,2}(?:[/.]\d{2,4})?\b#', ' ', $line);

            // Tutti gli importi tipo 99, 23,50, 1.234,00 ; si prende l'ultimo.
            if (! preg_match_all('/\d{1,3}(?:\.\d{3})*(?:,\d{1,2})?|\d+(?:[.,]\d{1,2})?/', (string) $clean, $m) || $m[0] === []) {
                continue;
            }

            $raw = end($m[0]);
            $amount = $this->toFloat($raw);
            if ($amount <= 0) {
                continue;
            }

            // Etichetta: la riga senza l'importo, con date e spazi/tab ripuliti.
            $label = str_replace($raw, '', $line);
            $label = preg_replace('#\b\d{1,2}[/.]\d{1,2}(?:[/.]\d{2,4})?\b#', ' ', (string) $label);
            $label = trim(preg_replace('/\s+/', ' ', (string) $label));
            $label = trim(preg_replace('/[:€\-\s]+$/u', '', $label));

            $parts[] = ['label' => $label, 'amount' => $amount];
        }

        return $parts;
    }

    /**
     * Converte un importo italiano ("1.234,50" o "99" o "23,50") in float.
     */
    private function toFloat(string $raw): float
    {
        $raw = str_replace('.', '', $raw);   // separatore migliaia
        $raw = str_replace(',', '.', $raw);  // decimale

        return round((float) $raw, 2);
    }

    /**
     * Deduce il conto dalla natura del testo (parole chiave). null se incerto.
     */
    public function contoFromText(?string $text): ?string
    {
        $t = Str::lower((string) $text);

        return match (true) {
            (bool) preg_match('/trenitalia|trenord|taxi|hotel|trasfert|autostrad|telepass|parch|volo|aereo|treno|carburant|benzin|pedagg/', $t) => 'Trasferte',
            (bool) preg_match('/pranzo|cena|ristor|pizz|\bbar\b|vitto|colazion|caff|osteria|trattoria/', $t) => 'Ristorazione',
            default => null,
        };
    }

    private function buildNote(Invoice $invoice, InvoiceItem $item, ?string $label): string
    {
        $prefix = "Riaddebito da fattura {$invoice->number}";
        $what = $label !== null && $label !== '' ? $label : (string) $item->name;

        return trim($prefix.' — '.$what, ' —');
    }
}
