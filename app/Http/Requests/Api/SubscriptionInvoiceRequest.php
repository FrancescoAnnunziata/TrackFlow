<?php

namespace App\Http\Requests\Api;

use App\Support\VatNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Corpo della chiamata che trasforma un abbonamento incassato in una fattura.
 *
 * Le regole sono volutamente permissive sulla forma (il chiamante normalizza) e
 * severe solo dove un dato sbagliato produce un documento fiscale sbagliato:
 * P.IVA, importi, periodo.
 */
class SubscriptionInvoiceRequest extends FormRequest
{
    /**
     * L'autorizzazione è già stata data dalla firma HMAC del middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $vat = $this->input('customer.vat_number');

        if (is_string($vat)) {
            $this->merge([
                'customer' => array_merge((array) $this->input('customer', []), [
                    'vat_number' => VatNumber::normalize($vat),
                ]),
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array
    {
        /** @var array<int, string> $sources */
        $sources = config('services.billing_api.sources', []);

        return [
            'source' => ['required', 'string', 'in:'.implode(',', $sources)],
            'source_id' => ['required', 'string', 'max:190'],

            'issued_at' => ['required', 'date'],
            'period.from' => ['required', 'date'],
            'period.to' => ['required', 'date', 'after_or_equal:period.from'],

            'subject' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'paid' => ['nullable', 'boolean'],
            'ei_payment_method' => ['nullable', 'string', 'regex:/^MP\d{2}$/'],

            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'max:190'],
            // Solo clienti italiani, per ora: fuori dall'Italia cambiano
            // l'aliquota, la natura IVA e il canale di trasmissione.
            'customer.vat_number' => ['required', 'string', 'regex:/^\d{11}$/'],
            'customer.tax_code' => ['nullable', 'string', 'max:16'],
            'customer.entity_type' => ['nullable', 'string', 'in:company,person'],
            'customer.address_street' => ['nullable', 'string', 'max:190'],
            'customer.address_postal_code' => ['nullable', 'string', 'max:10'],
            'customer.address_city' => ['nullable', 'string', 'max:120'],
            'customer.address_province' => ['nullable', 'string', 'max:4'],
            'customer.country_iso' => ['nullable', 'string', 'in:IT,it'],
            'customer.email' => ['nullable', 'email', 'max:190'],
            'customer.certified_email' => ['nullable', 'email', 'max:190'],
            'customer.ei_code' => ['nullable', 'string', 'max:7'],

            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.name' => ['required', 'string', 'max:190'],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
            'lines.*.qty' => ['nullable', 'numeric', 'not_in:0'],
            'lines.*.measure' => ['nullable', 'string', 'max:8'],
            'lines.*.net_price' => ['required', 'numeric'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                // Righe negative sono legittime (sconti, abbuoni), un totale
                // negativo no: quello sarebbe una nota di credito, che da qui
                // non si emette.
                $total = 0.0;
                foreach ((array) $this->input('lines', []) as $line) {
                    $total += (float) ($line['qty'] ?? 1) * (float) ($line['net_price'] ?? 0);
                }

                if (round($total, 2) <= 0) {
                    $validator->errors()->add('lines', 'Il totale imponibile deve essere maggiore di zero. Per stornare un importo serve una nota di credito, che si emette dal pannello.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'source.in' => 'Sorgente non riconosciuta: aggiungila a BILLING_API_SOURCES su TrackFlow.',
            'source_id.required' => 'Manca source_id: è la chiave che garantisce una sola fattura per pagamento.',
            'period.to.after_or_equal' => 'La fine del periodo non può precedere l\'inizio.',
            'customer.vat_number.regex' => 'Per ora emettiamo solo verso P.IVA italiane (11 cifre).',
            'customer.country_iso.in' => 'Per ora emettiamo solo verso clienti italiani.',
            'ei_payment_method.regex' => 'La modalità di pagamento SDI deve essere nel formato MPxx (es. MP08 per la carta).',
            'lines.required' => 'Serve almeno una riga di fattura.',
            'lines.*.net_price.required' => 'Ogni riga deve avere un prezzo unitario al netto dell\'IVA.',
        ];
    }
}
