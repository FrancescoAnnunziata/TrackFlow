<?php

namespace App\Assistant\Tools;

use App\Assistant\AssistantTool;
use App\Assistant\AssistantToolResult;
use App\Filament\Resources\BankTransactions\Tables\BankTransactionsTable;
use App\Models\Abbonamento;
use App\Models\BankTransaction;
use App\Models\PassiveInvoice;

/**
 * Prepara una PROPOSTA per segnare una spesa (movimento in uscita o fattura
 * passiva) come abbonamento trimestrale o annuale, come fa l'azione «Segna come
 * abbonamento» del pannello. NON scrive: la conferma la dà l'utente, e passa da
 * SegnaAbbonamento come l'azione.
 */
class ProposeAbbonamentoTool implements AssistantTool
{
    public function name(): string
    {
        return 'propose_mark_as_subscription';
    }

    public function description(): string
    {
        return 'Prepara una proposta per segnare una spesa come ABBONAMENTO che si rinnova da solo ogni tre mesi '
            .'(quarterly) o ogni anno (annual): software, domini, hosting. Dalla data dell\'addebito si calcola il prossimo '
            .'rinnovo, e una settimana prima arriva una mail di promemoria. Indica UNO fra movement_id (movimento in uscita) '
            .'e passive_invoice_id (fattura passiva). Se è il rinnovo di un abbonamento già in elenco, passa existing_id '
            .'(cercalo prima con read_subscriptions) invece di crearne uno nuovo. Non proporlo per costi mensili: '
            .'sono esclusi di proposito. Se non sai la cadenza, chiedila. NON esegue: crea una proposta che l\'utente conferma.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'movement_id' => ['type' => 'integer', 'description' => 'id del movimento in uscita'],
                'passive_invoice_id' => ['type' => 'integer', 'description' => 'id della fattura passiva'],
                'cadence' => ['type' => 'string', 'enum' => array_keys(Abbonamento::cadenceOptions()), 'description' => 'quarterly = ogni tre mesi, annual = ogni anno'],
                'name' => ['type' => 'string', 'description' => 'Nome dell\'abbonamento, per esempio «Vimeo» o «Dominio dolcitaliaexchange.it» (ignorato se passi existing_id)'],
                'existing_id' => ['type' => 'integer', 'description' => 'id dell\'abbonamento già in elenco di cui questa spesa è il rinnovo'],
            ],
            'required' => ['cadence'],
        ];
    }

    public function run(array $input): AssistantToolResult
    {
        $cadence = (string) ($input['cadence'] ?? '');
        if (! array_key_exists($cadence, Abbonamento::cadenceOptions())) {
            return AssistantToolResult::error('Cadenza non valida: usa quarterly (trimestrale) o annual (annuale). I mensili non si segnano.');
        }

        $movementId = isset($input['movement_id']) ? (int) $input['movement_id'] : null;
        $invoiceId = isset($input['passive_invoice_id']) ? (int) $input['passive_invoice_id'] : null;
        if (($movementId === null) === ($invoiceId === null)) {
            return AssistantToolResult::error('Indica uno solo fra movement_id e passive_invoice_id.');
        }

        if ($movementId !== null) {
            $tx = BankTransaction::with('bankAccount')->find($movementId);
            if ($tx === null) {
                return AssistantToolResult::error('Movimento non trovato (id '.$movementId.').');
            }
            if ($tx->direction !== BankTransaction::DIRECTION_OUT || $tx->isTransfer()) {
                return AssistantToolResult::error('Il movimento id '.$tx->id.' non è un\'uscita: non può essere un abbonamento.');
            }
            $source = ['movement_id' => $tx->id];
            $sourceLabel = sprintf(
                'Movimento id %d — %s — € %s — %s',
                $tx->id,
                trim((string) ($tx->description ?: $tx->counterparty)),
                number_format(abs((float) $tx->amount), 2, ',', '.'),
                $tx->booked_at->format('d/m/Y'),
            );
            $date = $tx->booked_at;
            $amount = round(abs((float) $tx->amount), 2);
            $supplierId = null;
            $defaultName = BankTransactionsTable::merchantName($tx);
        } else {
            $invoice = PassiveInvoice::with('supplier')->find($invoiceId);
            if ($invoice === null) {
                return AssistantToolResult::error('Fattura passiva non trovata (id '.$invoiceId.').');
            }
            if ($invoice->isCreditNote()) {
                return AssistantToolResult::error('La fattura id '.$invoice->id.' è una nota di credito: non è un abbonamento.');
            }
            $source = ['passive_invoice_id' => $invoice->id];
            $sourceLabel = sprintf(
                'Fattura passiva id %d — %s n. %s — € %s — %s',
                $invoice->id,
                $invoice->supplier->name ?? '—',
                $invoice->number ?: 's.n.',
                number_format((float) $invoice->amount_gross, 2, ',', '.'),
                $invoice->document_date->format('d/m/Y'),
            );
            $date = $invoice->document_date;
            $amount = round((float) $invoice->amount_gross, 2);
            $supplierId = $invoice->supplier_id;
            $defaultName = (string) ($invoice->supplier->name ?? 'Abbonamento');
        }

        $existing = null;
        if (isset($input['existing_id'])) {
            $existing = Abbonamento::find((int) $input['existing_id']);
            if ($existing === null) {
                return AssistantToolResult::error('Abbonamento non trovato (id '.$input['existing_id'].'): cercalo con read_subscriptions.');
            }
        }

        $name = $existing?->name ?? (trim((string) ($input['name'] ?? '')) ?: $defaultName);
        $next = Abbonamento::nextRenewalAfter($date, $cadence);
        $cadenceLabel = mb_strtolower(Abbonamento::cadenceOptions()[$cadence]);

        $action = $source + [
            'type' => 'subscription',
            'source_label' => $sourceLabel,
            'name' => mb_substr($name, 0, 120),
            'cadence' => $cadence,
            'existing_id' => $existing?->id,
            'amount' => $amount,
            'charged_on' => $date->format('Y-m-d'),
            'supplier_id' => $supplierId,
            'next_renewal_on' => $next->format('Y-m-d'),
        ];

        $content = "Proposta preparata (in attesa di conferma dell'utente, NON scritta):\n"
            ."Segna come ABBONAMENTO {$cadenceLabel}: {$sourceLabel}\n"
            .($existing ? "Rinnovo dell'abbonamento già in elenco id {$existing->id} ({$existing->name}).\n" : "Nuovo abbonamento: {$action['name']}.\n")
            .'Prossimo rinnovo: '.$next->format('d/m/Y').", promemoria una settimana prima.\n"
            ."Di' all'utente di confermare la proposta qui sotto per applicarla.";

        return AssistantToolResult::proposal($content, 'Proposta abbonamento: '.$action['name'], $action);
    }
}
