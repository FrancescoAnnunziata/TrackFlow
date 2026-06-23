<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Invoice extends Model
{
    protected $fillable = [
        'user_id',
        'client_id',
        'number',
        'issue_date',
        'period_from',
        'period_to',
        'hourly_rate',
        'vat_rate',
        'status',
        'notes',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'period_from' => 'date',
        'period_to' => 'date',
        'hourly_rate' => 'decimal:2',
        'vat_rate' => 'decimal:2',
    ];

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

    /**
     * Propone il prossimo numero fattura dell'anno corrente, saltando i numeri
     * già usati (es. dopo cancellazioni o numerazioni manuali) per non collidere
     * con il vincolo di unicità.
     */
    public static function suggestNextNumber(): string
    {
        $year = now()->year;
        $count = self::whereYear('issue_date', $year)->count();

        do {
            $count++;
            $number = sprintf('%d-%03d', $year, $count);
        } while (self::where('number', $number)->exists());

        return $number;
    }

    public function hoursSubtotal(): float
    {
        $totalMinutes = (int) $this->hours()->sum('minutes');

        return round(($totalMinutes / 60) * (float) $this->hourly_rate, 2);
    }

    public function expensesSubtotal(): float
    {
        return round((float) $this->expenses()->sum('amount'), 2);
    }

    public function taxableAmount(): float
    {
        return round($this->hoursSubtotal() + $this->expensesSubtotal(), 2);
    }

    public function vatAmount(): float
    {
        return round($this->taxableAmount() * ((float) $this->vat_rate / 100), 2);
    }

    public function total(): float
    {
        return round($this->taxableAmount() + $this->vatAmount(), 2);
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
        $this->loadMissing(['client', 'hours.user', 'expenses.user']);

        [$numerationPrefix, $numericNumber] = $this->splitNumber($this->number);

        $items = [];

        foreach ($this->hours as $hour) {
            $qty = round(((int) $hour->minutes) / 60, 2);
            $items[] = [
                'name' => 'Ore di consulenza',
                'description' => trim(sprintf(
                    '%s — %s%s',
                    optional($hour->date)->format('d/m/Y') ?? '',
                    $hour->user?->name ?? '',
                    $hour->notes ? ' — ' . $hour->notes : '',
                ), ' —'),
                'qty' => $qty,
                'measure' => 'h',
                'net_price' => (float) $this->hourly_rate,
                'category' => '',
                'discount' => 0,
                'vat' => [
                    'value' => (float) $this->vat_rate,
                ],
            ];
        }

        foreach ($this->expenses as $expense) {
            $items[] = [
                'name' => $expense->notes
                    ? str($expense->notes)->limit(80)->value()
                    : 'Spesa ' . (optional($expense->date)->format('d/m/Y') ?? ''),
                'description' => '',
                'qty' => 1,
                'measure' => '',
                'net_price' => (float) $expense->amount,
                'category' => '',
                'discount' => 0,
                'vat' => [
                    'value' => (float) $this->vat_rate,
                ],
            ];
        }

        return [
            'data' => [
                'type' => 'invoice',
                'entity' => $this->buildEntity(),
                'date' => optional($this->issue_date)->format('Y-m-d'),
                'number' => $numericNumber,
                'numeration' => $numerationPrefix,
                'subject' => sprintf(
                    'Periodo %s - %s',
                    optional($this->period_from)->format('d/m/Y') ?? '',
                    optional($this->period_to)->format('d/m/Y') ?? '',
                ),
                'visible_subject' => '',
                'notes' => (string) ($this->notes ?? ''),
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
                'e_invoice' => false,
                'items_list' => $items,
                'payments_list' => [
                    [
                        'amount' => $this->total(),
                        'due_date' => optional($this->issue_date)->format('Y-m-d'),
                        'status' => $this->status === 'paid' ? 'paid' : 'not_paid',
                    ],
                ],
            ],
        ];
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
        if (preg_match('/^(.*?)(\d+)$/', trim($raw), $m) === 1) {
            $prefix = rtrim($m[1], '-/ ');

            return [$prefix, (int) $m[2]];
        }

        return ['', 0];
    }
}
