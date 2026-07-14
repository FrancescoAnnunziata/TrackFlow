@php
    $eur = fn ($v) => '€ ' . number_format((float) $v, 2, ',', '.');
    $totale = $movimenti->sum(fn ($t) => abs((float) $t->amount));
@endphp

<div class="space-y-3">
    @if ($movimenti->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">Nessun movimento per questo periodo.</p>
    @else
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:.85rem;">
                <thead>
                    <tr>
                        <th style="text-align:left; padding:.4rem .6rem; border-bottom:1px solid #e5e7eb; font-size:.68rem; text-transform:uppercase; color:#6b7280;">Data</th>
                        <th style="text-align:left; padding:.4rem .6rem; border-bottom:1px solid #e5e7eb; font-size:.68rem; text-transform:uppercase; color:#6b7280;">Descrizione</th>
                        <th style="text-align:left; padding:.4rem .6rem; border-bottom:1px solid #e5e7eb; font-size:.68rem; text-transform:uppercase; color:#6b7280;">Conto</th>
                        <th style="text-align:center; padding:.4rem .6rem; border-bottom:1px solid #e5e7eb; font-size:.68rem; text-transform:uppercase; color:#6b7280;">Ric.</th>
                        <th style="text-align:right; padding:.4rem .6rem; border-bottom:1px solid #e5e7eb; font-size:.68rem; text-transform:uppercase; color:#6b7280;">Importo</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($movimenti as $t)
                        <tr>
                            <td style="padding:.4rem .6rem; border-bottom:1px solid #f3f4f6; white-space:nowrap;">
                                {{ optional($t->booked_at)->format('d/m/Y') }}
                            </td>
                            <td style="padding:.4rem .6rem; border-bottom:1px solid #f3f4f6;">
                                <a
                                    href="{{ \App\Filament\Resources\BankTransactions\BankTransactionResource::getUrl('edit', ['record' => $t]) }}"
                                    style="color:#2563eb; text-decoration:underline;"
                                >
                                    {{ \Illuminate\Support\Str::limit($t->description ?: ($t->counterparty ?? '—'), 60) }}
                                </a>
                            </td>
                            <td style="padding:.4rem .6rem; border-bottom:1px solid #f3f4f6; white-space:nowrap;">
                                {{ $t->bankAccount->name ?? '—' }}
                            </td>
                            <td style="padding:.4rem .6rem; border-bottom:1px solid #f3f4f6; text-align:center;">
                                {{ $t->reconciliations_count > 0 ? '✓' : '—' }}
                            </td>
                            <td style="padding:.4rem .6rem; border-bottom:1px solid #f3f4f6; text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; color:{{ (float) $t->amount >= 0 ? '#16a34a' : '#dc2626' }};">
                                {{ $eur($t->amount) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" style="padding:.5rem .6rem; border-top:2px solid #d1d5db; font-weight:700; text-align:right;">
                            Totale ({{ $movimenti->count() }} movimenti)
                        </td>
                        <td style="padding:.5rem .6rem; border-top:2px solid #d1d5db; font-weight:700; text-align:right; font-variant-numeric:tabular-nums;">
                            {{ $eur($totale) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
