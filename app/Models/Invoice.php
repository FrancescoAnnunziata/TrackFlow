<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class Invoice extends Model
{
    /** Fattura ordinaria emessa. */
    public const TYPE_INVOICE = 'invoice';

    /** Nota di credito attiva: storna (in parte o del tutto) una fattura emessa. */
    public const TYPE_CREDIT_NOTE = 'credit_note';

    protected $fillable = [
        'user_id',
        'client_id',
        'number',
        'type',
        'related_invoice_id',
        'issue_date',
        'period_from',
        'period_to',
        'hourly_rate',
        'vat_rate',
        'status',
        'notes',
        'fic_document_id',
        'fic_document_token',
        'fic_sent_at',
        'imported',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'period_from' => 'date',
        'period_to' => 'date',
        'hourly_rate' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'fic_sent_at' => 'datetime',
        'imported' => 'boolean',
    ];

    /**
     * True se la fattura è già stata creata su Fatture in Cloud.
     */
    public function isSentToFic(): bool
    {
        return $this->fic_sent_at !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function hours(): BelongsToMany
    {
        return $this->belongsToMany(Hour::class)->withTimestamps();
    }

    public function expenses(): BelongsToMany
    {
        return $this->belongsToMany(Expense::class)->withTimestamps();
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort');
    }

    public function reconciliations(): MorphMany
    {
        return $this->morphMany(Reconciliation::class, 'reconcilable');
    }

    /**
     * Fattura stornata da questa nota di credito.
     */
    public function relatedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'related_invoice_id');
    }

    /**
     * Note di credito che stornano questa fattura.
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(Invoice::class, 'related_invoice_id')
            ->where('type', self::TYPE_CREDIT_NOTE);
    }

    public function isCreditNote(): bool
    {
        return $this->type === self::TYPE_CREDIT_NOTE;
    }

    /**
     * Quota già riconciliata con movimenti bancari.
     */
    public function reconciledAmount(): float
    {
        return round((float) $this->reconciliations()->sum('amount'), 2);
    }

    /**
     * Totale stornato dalle note di credito collegate (lordo).
     */
    public function creditedAmount(): float
    {
        return round((float) $this->creditNotes->sum(fn (Invoice $note): float => $note->total()), 2);
    }

    /**
     * Importo effettivamente da incassare: totale meno gli storni delle note di
     * credito collegate. È il bersaglio della riconciliazione bancaria.
     */
    public function amountToCollect(): float
    {
        return round($this->total() - $this->creditedAmount(), 2);
    }

    /**
     * True se la fattura ha righe esplicite (generate dal motore): in tal caso
     * sono la fonte di totali e payload. Altrimenti si usa il calcolo legacy
     * ore×tariffa.
     */
    public function usesItems(): bool
    {
        return $this->items->isNotEmpty();
    }

    /**
     * Ricostruisce il numero "N/anno" dal documento restituito da FIC (la
     * numerazione è decisa da FIC, TrackFlow la eredita).
     *
     * @param  array<string, mixed>  $doc
     */
    public static function formatFicNumber(array $doc): ?string
    {
        $num = $doc['number'] ?? null;

        if ($num === null || $num === '') {
            return null;
        }

        $numeration = trim((string) ($doc['numeration'] ?? ''));
        $year = isset($doc['date']) ? Carbon::parse($doc['date'])->year : now()->year;

        return ($numeration !== '' ? $numeration.' ' : '').(int) $num.'/'.$year;
    }

    public function hoursSubtotal(): float
    {
        $totalHours = (float) $this->hours()->sum('hours');

        return round($totalHours * (float) $this->hourly_rate, 2);
    }

    public function expensesSubtotal(): float
    {
        return round((float) $this->expenses()->sum('amount'), 2);
    }

    /**
     * Somma delle righe soggette a IVA piena (base imponibile IVA).
     */
    public function standardBase(): float
    {
        return round($this->items
            ->where('vat_kind', InvoiceItem::VAT_STANDARD)
            ->sum(fn (InvoiceItem $i): float => $i->lineTotal()), 2);
    }

    /**
     * Somma delle righe art. 15 (rimborsi esclusi da IVA): entrano nel totale
     * ma non nella base IVA.
     */
    public function art15Total(): float
    {
        return round($this->items
            ->where('vat_kind', InvoiceItem::VAT_ART15)
            ->sum(fn (InvoiceItem $i): float => $i->lineTotal()), 2);
    }

    /**
     * Imponibile (base IVA). Con righe esplicite = somma righe standard;
     * altrimenti calcolo legacy ore + spese.
     */
    public function taxableAmount(): float
    {
        if ($this->usesItems()) {
            return $this->standardBase();
        }

        return round($this->hoursSubtotal() + $this->expensesSubtotal(), 2);
    }

    public function vatAmount(): float
    {
        return round($this->taxableAmount() * ((float) $this->vat_rate / 100), 2);
    }

    public function total(): float
    {
        $art15 = $this->usesItems() ? $this->art15Total() : 0.0;

        return round($this->taxableAmount() + $this->vatAmount() + $art15, 2);
    }

    /**
     * Build a Fatture in Cloud `issued_documents` payload.
     *
     * Schema reference: POST /c/{company_id}/issued_documents — the returned
     * array can be JSON-encoded and sent as the request body.
     *
     * @return array<string, mixed>
     */
    public function toFicPayload(): array
    {
        $this->loadMissing(['client', 'hours.user', 'expenses.user', 'items']);

        [$numerationPrefix, $numericNumber] = $this->splitNumber((string) $this->number);

        if ($this->usesItems()) {
            $items = $this->buildItemsFromLines();
            $notes = $this->buildNotesHtml();
            $subject = '';
            $visibleSubject = $this->buildVisibleSubject();
        } else {
            $items = $this->buildLegacyItems();
            $notes = (string) ($this->notes ?? '');
            $subject = sprintf(
                'Periodo %s - %s',
                optional($this->period_from)->format('d/m/Y') ?? '',
                optional($this->period_to)->format('d/m/Y') ?? '',
            );
            $visibleSubject = '';
        }

        $data = [
            'type' => 'invoice',
            'entity' => $this->buildEntity(),
            'date' => optional($this->issue_date)->format('Y-m-d'),
            'number' => $numericNumber,
            'numeration' => $numerationPrefix,
            'subject' => $subject,
            'visible_subject' => $visibleSubject,
            'notes' => $notes,
            'currency' => [
                'id' => 'EUR',
                'symbol' => '€',
                'exchange_rate' => '1.00000',
                'html_symbol' => '&euro;',
            ],
            'language' => [
                'code' => 'it',
                'name' => 'italiano',
            ],
            'use_gross_prices' => false,
            'e_invoice' => (bool) config('services.fic.e_invoice', true),
            'items_list' => $items,
            'payments_list' => [
                [
                    'amount' => $this->total(),
                    'due_date' => optional($this->issue_date)->format('Y-m-d'),
                    'status' => $this->status === 'paid' ? 'paid' : 'not_paid',
                ],
            ],
        ];

        if ($this->client?->payment_method_id) {
            $data['payment_method'] = ['id' => (int) $this->client->payment_method_id];
        }

        // Fattura elettronica: l'XML per il SDI richiede la ModalitaPagamento.
        // Senza, FIC rifiuta il documento.
        if ($data['e_invoice']) {
            $data['ei_data'] = ['payment_method' => $this->eiPaymentMethod()];
        }

        return ['data' => $data];
    }

    /**
     * ModalitaPagamento SDI da mettere nell'XML: quella del cliente, altrimenti
     * il default di config (MP05, bonifico).
     */
    public function eiPaymentMethod(): string
    {
        $clientValue = trim((string) ($this->client?->ei_payment_method ?? ''));

        return $clientValue !== ''
            ? $clientValue
            : (string) config('services.fic.ei_payment_method', 'MP05');
    }

    /**
     * Righe FIC dalle invoice_items: le art. 15 usano l'id IVA "escluso"
     * configurato, le altre l'aliquota standard della fattura.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildItemsFromLines(): array
    {
        $art15VatId = (int) config('services.fic.art15_vat_id');
        $standardVatId = (int) config('services.fic.standard_vat_id');

        return $this->items->map(fn (InvoiceItem $item): array => [
            'name' => $item->name,
            'description' => (string) ($item->description ?? ''),
            'qty' => (float) $item->qty,
            'measure' => (string) ($item->measure ?? ''),
            'net_price' => (float) $item->net_price,
            'category' => '',
            'discount' => 0,
            // FIC richiede vat.id su ogni riga.
            'vat' => $item->isArt15()
                ? ['id' => $art15VatId]
                : ['id' => $standardVatId, 'value' => (float) $this->vat_rate],
        ])->values()->all();
    }

    /**
     * Payload legacy (fatture senza righe esplicite): una riga per ora e una
     * per spesa, come prima dell'introduzione del motore.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildLegacyItems(): array
    {
        $standardVatId = (int) config('services.fic.standard_vat_id');
        $items = [];

        foreach ($this->hours as $hour) {
            $items[] = [
                'name' => 'Ore di consulenza',
                'description' => trim(sprintf(
                    '%s — %s%s',
                    optional($hour->date)->format('d/m/Y') ?? '',
                    $hour->user?->name ?? '',
                    $hour->notes ? ' — '.$hour->notes : '',
                ), ' —'),
                'qty' => round((float) $hour->hours, 2),
                'measure' => 'h',
                'net_price' => (float) $this->hourly_rate,
                'category' => '',
                'discount' => 0,
                'vat' => ['id' => $standardVatId, 'value' => (float) $this->vat_rate],
            ];
        }

        foreach ($this->expenses as $expense) {
            $items[] = [
                'name' => $expense->notes
                    ? str($expense->notes)->limit(80)->value()
                    : 'Spesa '.(optional($expense->date)->format('d/m/Y') ?? ''),
                'description' => '',
                'qty' => 1,
                'measure' => '',
                'net_price' => (float) $expense->amount,
                'category' => '',
                'discount' => 0,
                'vat' => ['id' => $standardVatId, 'value' => (float) $this->vat_rate],
            ];
        }

        return $items;
    }

    /**
     * Titolo umano della fattura (visible_subject FIC): etichetta consulenza
     * del cliente + periodo.
     */
    public function buildVisibleSubject(): string
    {
        $label = trim((string) ($this->client?->consulting_label ?? '')) ?: 'Consulenza';

        if ($this->period_from && $this->period_to) {
            $period = $this->period_from->isSameMonth($this->period_to)
                ? $this->period_from->locale('it')->translatedFormat('F Y')
                : $this->period_from->format('d/m/Y').' - '.$this->period_to->format('d/m/Y');

            return trim($label.' '.$period);
        }

        return $label;
    }

    /**
     * Note a piè di pagina: eventuali note manuali + tabella dettaglio spese
     * (Data | Importo | Note | Giustificativo con link alla foto).
     */
    public function buildNotesHtml(): string
    {
        $manual = trim((string) ($this->notes ?? ''));
        $table = $this->expensesNotesTable();

        return trim($manual.($table !== '' ? ($manual !== '' ? "\n" : '').$table : ''));
    }

    private function expensesNotesTable(): string
    {
        if ($this->expenses->isEmpty()) {
            return '';
        }

        $cell = 'padding:2px 6px;vertical-align:top;';
        $header = sprintf(
            '<tr><td style="%s">Data</td><td style="%s">Importo</td><td style="%s">Note</td><td style="%s">Giustificativo</td></tr>',
            $cell, $cell, $cell, $cell,
        );

        $rows = '';
        foreach ($this->expenses->sortBy('date') as $expense) {
            $atts = $expense->attachaments ?? [];
            $links = collect($atts)
                ->map(fn (string $path, int $i): string => sprintf(
                    '<a href="%s">%s%s</a>',
                    e(Storage::disk('public')->url($path)),
                    str_ends_with(strtolower($path), '.pdf') ? 'PDF' : 'foto',
                    count($atts) > 1 ? ' '.($i + 1) : '',
                ))
                ->implode(' ');

            $rows .= sprintf(
                '<tr><td style="%s">%s</td><td style="%stext-align:right;">%s</td><td style="%s">%s</td><td style="%s">%s</td></tr>',
                $cell, e(optional($expense->date)->format('d/m/Y') ?? ''),
                $cell, e(number_format((float) $expense->amount, 2, ',', '.')),
                $cell, e((string) ($expense->notes ?? '')),
                $cell, $links !== '' ? $links : '—',
            );
        }

        return '<table style="font-size:10pt;font-family:Arial;border-collapse:collapse;"><tbody>'.$header.$rows.'</tbody></table>';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEntity(): array
    {
        $client = $this->client;

        return [
            'name' => $client->name,
            'type' => $client->entity_type ?? 'company',
            'vat_number' => (string) ($client->vat_number ?? ''),
            'tax_code' => (string) ($client->tax_code ?? ''),
            'address_street' => (string) ($client->address_street ?? ''),
            'address_postal_code' => (string) ($client->address_postal_code ?? ''),
            'address_city' => (string) ($client->address_city ?? ''),
            'address_province' => (string) ($client->address_province ?? ''),
            'country' => (string) ($client->country ?? 'Italia'),
            'country_iso' => (string) ($client->country_iso ?? 'IT'),
            'email' => (string) ($client->email ?? ''),
            'certified_email' => (string) ($client->certified_email ?? ''),
            'ei_code' => (string) ($client->ei_code ?? ''),
        ];
    }

    /**
     * Split a free-form invoice number like "2026-007" or "/A-3" into a
     * numeration prefix and a numeric counter, matching the FIC convention.
     *
     * @return array{0: string, 1: int}
     */
    private function splitNumber(string $raw): array
    {
        $raw = trim($raw);

        // Formato "N/anno": il numero FIC è N, senza numerazione (l'anno lo
        // gestisce FIC in base alla data del documento).
        if (preg_match('#^(\d+)/\d{4}$#', $raw, $m) === 1) {
            return ['', (int) $m[1]];
        }

        if (preg_match('/^(.*?)(\d+)$/', $raw, $m) === 1) {
            $prefix = rtrim($m[1], '-/ ');

            return [$prefix, (int) $m[2]];
        }

        return ['', 0];
    }
}
