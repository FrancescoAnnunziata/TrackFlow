@php
    $eur = fn ($v) => '€ ' . number_format((float) $v, 2, ',', '.');
    $totale = $documenti->sum(fn ($r) => (float) $r['importo']);
@endphp

<div class="space-y-3">
    @if ($documenti->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">Nessun documento per questo periodo.</p>
    @else
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:.85rem;">
                <thead>
                    <tr>
                        <th style="text-align:left; padding:.4rem .6rem; border-bottom:1px solid #e5e7eb; font-size:.68rem; text-transform:uppercase; color:#6b7280;">Data</th>
                        <th style="text-align:left; padding:.4rem .6rem; border-bottom:1px solid #e5e7eb; font-size:.68rem; text-transform:uppercase; color:#6b7280;">Documento</th>
                        <th style="text-align:left; padding:.4rem .6rem; border-bottom:1px solid #e5e7eb; font-size:.68rem; text-transform:uppercase; color:#6b7280;">{{ $tipo === 'costi' ? 'Fornitore / voce' : 'Cliente' }}</th>
                        <th style="text-align:left; padding:.4rem .6rem; border-bottom:1px solid #e5e7eb; font-size:.68rem; text-transform:uppercase; color:#6b7280;">Tipo</th>
                        <th style="text-align:right; padding:.4rem .6rem; border-bottom:1px solid #e5e7eb; font-size:.68rem; text-transform:uppercase; color:#6b7280;">Imponibile</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($documenti as $r)
                        <tr>
                            <td style="padding:.4rem .6rem; border-bottom:1px solid #f3f4f6; white-space:nowrap;">
                                {{ optional($r['data'])->format('d/m/Y') ?? '—' }}
                            </td>
                            <td style="padding:.4rem .6rem; border-bottom:1px solid #f3f4f6;">
                                @if (!empty($r['url']))
                                    <a href="{{ $r['url'] }}" style="color:#2563eb; text-decoration:underline;">
                                        {{ \Illuminate\Support\Str::limit($r['numero'], 50) }}
                                    </a>
                                @else
                                    {{ \Illuminate\Support\Str::limit($r['numero'], 50) }}
                                @endif
                            </td>
                            <td style="padding:.4rem .6rem; border-bottom:1px solid #f3f4f6;">
                                {{ \Illuminate\Support\Str::limit($r['controparte'], 40) }}
                            </td>
                            <td style="padding:.4rem .6rem; border-bottom:1px solid #f3f4f6; white-space:nowrap; color:#6b7280;">
                                {{ $r['tipo'] }}
                            </td>
                            <td style="padding:.4rem .6rem; border-bottom:1px solid #f3f4f6; text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; color:{{ (float) $r['importo'] < 0 ? '#dc2626' : '#374151' }};">
                                {{ $eur($r['importo']) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" style="padding:.5rem .6rem; border-top:2px solid #d1d5db; font-weight:700; text-align:right;">
                            Totale ({{ $documenti->count() }} documenti)
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
