@php
    use App\Models\Quote;

    $euro = fn ($valore) => '€ ' . number_format((float) $valore, 2, ',', '.');
    $ore = fn ($valore) => rtrim(rtrim(number_format((float) $valore, 1, ',', '.'), '0'), ',');

    $client = $quote->client;
    $validoFino = $quote->validUntil();
    $firma = $quote->signatureDataUri();
    // L'intestazione scelta sul preventivo (default se non scelta).
    $emittente = $quote->emittente();
    $logo = $emittente->logoDataUri();
@endphp

{{--
    Corpo del documento: identico nella pagina web e nel PDF, perché il cliente
    deve firmare esattamente ciò che poi scarica. Solo tabelle e CSS di base:
    dompdf non conosce flexbox né grid.
--}}
<style>
    .doc { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; font-size: 11px; color: #1f2937; line-height: 1.5; }
    .doc table { width: 100%; border-collapse: collapse; }
    .doc h2 { font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: #6b7280; margin: 22px 0 8px; font-weight: 700; }
    .doc p { margin: 0 0 6px; }

    .doc-logo { height: 34px; margin-bottom: 8px; }
    .doc-issuer { font-size: 10px; color: #4b5563; }
    .doc-issuer .name { font-size: 14px; font-weight: 700; color: #111827; display: block; margin-bottom: 2px; }
    .doc-title { text-align: right; }
    .doc-title .kind { font-size: 20px; font-weight: 700; color: #111827; letter-spacing: 0.02em; }
    .doc-title .meta { font-size: 11px; color: #4b5563; margin-top: 4px; }

    .doc-rule { border: 0; border-top: 2px solid #111827; margin: 14px 0 18px; }

    .doc-parties td { vertical-align: top; padding: 0; }
    .doc-parties .label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.08em; color: #9ca3af; margin-bottom: 4px; }
    .doc-parties .client-name { font-size: 13px; font-weight: 700; color: #111827; }
    .doc-parties .terms { text-align: right; font-size: 10px; color: #4b5563; }

    .doc-items th { background: #f3f4f6; text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 0.06em; color: #4b5563; padding: 8px; border-bottom: 1px solid #e5e7eb; }
    .doc-items td { padding: 10px 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
    .doc-items .num { text-align: right; white-space: nowrap; }

    .doc-totals { margin-top: 12px; }
    .doc-totals td { padding: 4px 8px; }
    .doc-totals .lbl { text-align: right; color: #4b5563; }
    .doc-totals .val { text-align: right; width: 120px; white-space: nowrap; }
    .doc-totals .grand td { border-top: 2px solid #111827; font-size: 14px; font-weight: 700; color: #111827; padding-top: 8px; }

    .doc-terms { padding-left: 16px; margin: 0; color: #4b5563; }
    .doc-terms li { margin-bottom: 4px; }

    .doc-note { background: #f9fafb; border-left: 3px solid #d1d5db; padding: 8px 12px; color: #4b5563; }

    .doc-sign { margin-top: 26px; }
    .doc-sign td { vertical-align: top; width: 50%; padding: 0 12px; }
    .doc-sign .label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.08em; color: #9ca3af; margin-bottom: 6px; }
    .doc-sign .box { border-bottom: 1px solid #9ca3af; height: 68px; }
    .doc-sign .box.pending { border: 1px dashed #d1d5db; text-align: center; color: #9ca3af; font-size: 10px; padding-top: 26px; height: 42px; }
    .doc-sign img { max-height: 62px; max-width: 100%; }
    .doc-sign .who { margin-top: 6px; font-size: 10px; color: #4b5563; }
    .doc-sign .who strong { color: #111827; }

    .doc-audit { margin-top: 18px; font-size: 9px; color: #9ca3af; line-height: 1.4; }
    .doc-rejected { margin-top: 20px; border: 1px solid #fecaca; background: #fef2f2; color: #991b1b; padding: 10px 12px; }
</style>

<div class="doc">
    <table class="doc-head">
        <tr>
            <td style="width: 58%; vertical-align: top;">
                @if ($logo)
                    <img src="{{ $logo }}" alt="" class="doc-logo">
                @endif
                <div class="doc-issuer">
                    <span class="name">{{ $emittente->nome() }}</span>
                    @if ($sottotitolo = $emittente->sottotitolo())
                        <div>{{ $sottotitolo }}</div>
                    @endif
                    @if ($indirizzo = $emittente->indirizzo())
                        <div>{{ $indirizzo }}</div>
                    @endif
                    @foreach ($emittente->righeIdentificative() as $riga)
                        <div>{{ $riga }}</div>
                    @endforeach
                </div>
            </td>
            <td class="doc-title" style="vertical-align: top;">
                <div class="kind">PREVENTIVO</div>
                <div class="meta">
                    n. <strong>{{ $quote->number }}</strong><br>
                    del {{ $quote->issue_date?->format('d/m/Y') }}
                </div>
            </td>
        </tr>
    </table>

    <hr class="doc-rule">

    <table class="doc-parties">
        <tr>
            <td style="width: 60%;">
                <div class="label">Spett.le</div>
                <div class="client-name">{{ $client->name }}</div>
                @if ($indirizzoCliente = $client->fullAddress())
                    <div>{{ $indirizzoCliente }}</div>
                @endif
                @if ($client->vat_number)
                    <div>P.IVA {{ $client->vat_number }}</div>
                @endif
                @if ($client->tax_code && $client->tax_code !== $client->vat_number)
                    <div>C.F. {{ $client->tax_code }}</div>
                @endif
            </td>
            <td class="terms">
                @if ($validoFino)
                    <div>Offerta valida fino al <strong>{{ $validoFino->format('d/m/Y') }}</strong></div>
                @endif
                <div>Emesso da {{ $quote->user->name }}</div>
            </td>
        </tr>
    </table>

    @if (filled($quote->description))
        <h2>Oggetto dell'intervento</h2>
        <p>{!! nl2br(e($quote->description)) !!}</p>
    @endif

    <h2>Dettaglio economico</h2>
    <table class="doc-items">
        <thead>
            <tr>
                <th>Descrizione</th>
                <th class="num" style="width: 90px;">Ore stimate</th>
                <th class="num" style="width: 110px;">Tariffa oraria</th>
                <th class="num" style="width: 110px;">Imponibile</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    {{ $client->consulting_label ?: 'Attività di consulenza e assistenza tecnica' }}
                    @if (filled($quote->description))
                        <div style="color: #6b7280; margin-top: 3px;">Come da oggetto sopra indicato.</div>
                    @endif
                </td>
                <td class="num">{{ $ore($quote->estimated_hours) }} h</td>
                <td class="num">{{ $euro($quote->hourly_rate) }}</td>
                <td class="num">{{ $euro($quote->taxableAmount()) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="doc-totals">
        <tr>
            <td class="lbl">Imponibile</td>
            <td class="val">{{ $euro($quote->taxableAmount()) }}</td>
        </tr>
        <tr>
            <td class="lbl">IVA {{ rtrim(rtrim(number_format((float) $quote->vat_rate, 2, ',', '.'), '0'), ',') }}%</td>
            <td class="val">{{ $euro($quote->vatAmount()) }}</td>
        </tr>
        <tr class="grand">
            <td class="lbl">Totale</td>
            <td class="val">{{ $euro($quote->total()) }}</td>
        </tr>
    </table>

    @if ($condizioni = $emittente->condizioni())
        <h2>Condizioni</h2>
        <ol class="doc-terms">
            @foreach ($condizioni as $condizione)
                <li>{{ $condizione }}</li>
            @endforeach
        </ol>
    @endif

    @if (filled($quote->notes))
        <h2>Note</h2>
        <div class="doc-note">{!! nl2br(e($quote->notes)) !!}</div>
    @endif

    @if ($iban = $emittente->iban())
        <h2>Pagamento</h2>
        <p>Bonifico bancario — IBAN {{ $iban }}</p>
    @endif

    @if ($nota = $emittente->notaFiscale())
        <p style="margin-top: 14px; font-size: 9px; color: #9ca3af;">{{ $nota }}</p>
    @endif

    @if ($quote->status === Quote::STATUS_REJECTED)
        <div class="doc-rejected">
            <strong>Preventivo rifiutato</strong>
            il {{ $quote->rejected_at?->format('d/m/Y H:i') }}.
            @if (filled($quote->rejection_reason))
                <div style="margin-top: 4px;">Motivo: {{ $quote->rejection_reason }}</div>
            @endif
        </div>
    @endif

    <table class="doc-sign">
        <tr>
            <td>
                <div class="label">L'emittente</div>
                <div class="box"></div>
                <div class="who"><strong>{{ $emittente->nome() }}</strong></div>
            </td>
            <td>
                <div class="label">Per accettazione — il Cliente</div>
                @if ($firma)
                    <div class="box"><img src="{{ $firma }}" alt="Firma del cliente"></div>
                    <div class="who">
                        <strong>{{ $quote->signer_name }}</strong>
                        @if (filled($quote->signer_role))
                            — {{ $quote->signer_role }}
                        @endif
                        <br>
                        Firmato il {{ $quote->accepted_at?->format('d/m/Y') }} alle {{ $quote->accepted_at?->format('H:i') }}
                    </div>
                @else
                    <div class="box pending">Spazio riservato alla firma del cliente</div>
                    <div class="who">{{ $client->name }}</div>
                @endif
            </td>
        </tr>
    </table>

    @if ($firma)
        <div class="doc-audit">
            Documento accettato con firma grafica apposta online tramite {{ config('app.name') }}.
            Sottoscritto da {{ $quote->acceptedBy?->full_name ?: $quote->signer_name }}
            @if ($quote->acceptedBy?->email)
                ({{ $quote->acceptedBy->email }})
            @endif
            il {{ $quote->accepted_at?->format('d/m/Y H:i:s') }}
            @if ($quote->signature_ip)
                — indirizzo IP {{ $quote->signature_ip }}
            @endif
            — riferimento documento #{{ $quote->getKey() }}/{{ $quote->number }}.
        </div>
    @endif
</div>
