<x-filament-panels::page>
    @php
        $d = $this->dati();
        $g = $d['g8labs'];
        $gt = $d['g8labsTotali'];
        $snap = $d['snapshot'];
        $f = $d['forfettario'];
        $eur = fn ($v) => '€ ' . number_format((float) $v, 2, ',', '.');
        $pct = fn ($v) => number_format((float) $v, 1, ',', '.') . '%';
    @endphp

    <style>
        .fd-grid { display:grid; gap:1rem; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); }
        .fd-card { background:#fff; border:1px solid #e5e7eb; border-radius:.75rem; padding:1rem; }
        .dark .fd-card { background:rgba(255,255,255,.03); border-color:rgba(255,255,255,.1); }
        .fd-lbl { font-size:.7rem; text-transform:uppercase; letter-spacing:.04em; color:#6b7280; font-weight:600; }
        .dark .fd-lbl { color:#9ca3af; }
        .fd-val { font-size:1.55rem; font-weight:700; margin-top:.2rem; color:#111827; line-height:1.2; }
        .dark .fd-val { color:#f9fafb; }
        .fd-sub { font-size:.7rem; color:#9ca3af; margin-top:.25rem; }
        .fd-pos { color:#16a34a; } .dark .fd-pos { color:#4ade80; }
        .fd-neg { color:#dc2626; } .dark .fd-neg { color:#f87171; }
        .fd-warn { color:#d97706; } .dark .fd-warn { color:#fbbf24; }
        .fd-callout { border-radius:.75rem; padding:.7rem 1rem; font-size:.85rem; background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
        .dark .fd-callout { background:rgba(251,191,36,.08); border-color:rgba(251,191,36,.25); color:#fcd34d; }
        .fd-mini { display:flex; justify-content:space-between; font-size:.72rem; color:#6b7280; padding:.1rem 0; }
        .dark .fd-mini { color:#9ca3af; }
        .fd-tablewrap { overflow-x:auto; }
        .fd-table { width:100%; border-collapse:collapse; font-size:.85rem; }
        .fd-table th { text-align:right; padding:.5rem .7rem; font-size:.68rem; text-transform:uppercase; letter-spacing:.03em; color:#6b7280; border-bottom:1px solid #e5e7eb; font-weight:600; white-space:nowrap; }
        .fd-table th:first-child { text-align:left; }
        .dark .fd-table th { color:#9ca3af; border-color:rgba(255,255,255,.12); }
        .fd-table td { text-align:right; padding:.45rem .7rem; border-bottom:1px solid #f3f4f6; color:#374151; font-variant-numeric:tabular-nums; white-space:nowrap; }
        .fd-table td:first-child { text-align:left; font-weight:600; }
        .dark .fd-table td { color:#d1d5db; border-color:rgba(255,255,255,.06); }
        .fd-table tr.fd-empty td { color:#9ca3af; } .dark .fd-table tr.fd-empty td { color:#6b7280; }
        .fd-table tfoot td { font-weight:700; border-top:2px solid #d1d5db; border-bottom:0; color:#111827; }
        .dark .fd-table tfoot td { color:#f9fafb; border-color:rgba(255,255,255,.2); }
        .fd-bartrack { height:.6rem; border-radius:9999px; background:#f3f4f6; overflow:hidden; margin-top:.5rem; }
        .dark .fd-bartrack { background:rgba(255,255,255,.1); }
        .fd-bar { height:100%; border-radius:9999px; }
        .fd-year { border-radius:.5rem; border:1px solid #d1d5db; padding:.35rem .6rem; font-size:.9rem; background:#fff; color:#111; }
        .dark .fd-year { background:rgba(255,255,255,.05); border-color:rgba(255,255,255,.15); color:#eee; }
    </style>

    {{-- Selettore anno --}}
    <div style="display:flex; align-items:center; gap:.6rem;">
        <span class="fd-lbl">Anno</span>
        <select wire:model.live="year" class="fd-year">
            @foreach ($this->anniDisponibili() as $y)
                <option value="{{ $y }}">{{ $y }}</option>
            @endforeach
        </select>
    </div>

    {{-- ============================ G8LABS ============================ --}}
    <x-filament::section>
        <x-slot name="heading">G8LABS — SRL (regime ordinario)</x-slot>
        <x-slot name="description">Ricavi, costi e margine per l'anno {{ $year }}. Il margine considera i soli costi documentati.</x-slot>

        {{-- Riepilogo annuo --}}
        <div class="fd-grid">
            <div class="fd-card">
                <div class="fd-lbl">Ricavi {{ $year }}</div>
                <div class="fd-val">{{ $eur($gt['ricavi']) }}</div>
                <div class="fd-sub">quanto hai fatturato (imponibile)</div>
            </div>
            <div class="fd-card">
                <div class="fd-lbl">Costi documentati</div>
                <div class="fd-val">{{ $eur($gt['costi']) }}</div>
                <div class="fd-sub">fatture passive, costi e spese</div>
            </div>
            <div class="fd-card">
                <div class="fd-lbl">Margine contabile</div>
                <div class="fd-val {{ $gt['margine'] >= 0 ? 'fd-pos' : 'fd-neg' }}">{{ $eur($gt['margine']) }}</div>
                <div class="fd-sub">ricavi − costi documentati</div>
            </div>
            <div class="fd-card">
                <div class="fd-lbl">IVA da versare</div>
                <div class="fd-val">{{ $eur($gt['iva_saldo']) }}</div>
                <div class="fd-sub">IVA vendite − IVA acquisti</div>
            </div>
        </div>

        {{-- Avviso uscite non attribuite --}}
        @if ($gt['non_attribuite'] > 0)
            <div class="fd-callout" style="margin-top:1rem;">
                ⚠️ Ci sono <strong>{{ $eur($gt['non_attribuite']) }}</strong> di uscite bancarie {{ $year }} <strong>non ancora collegate</strong> a una fattura passiva, un costo o una spesa: <strong>non sono contate nel margine contabile</strong>. Categorizzandole, il margine reale scenderà. Vedi la colonna "Non attrib." qui sotto e la cassa reale.
            </div>
        @endif

        {{-- Cassa reale + liquidità --}}
        <div class="fd-grid" style="margin-top:1rem;">
            <div class="fd-card">
                <div class="fd-lbl">Liquidità sui conti</div>
                <div class="fd-val">{{ $eur($snap['liquidita']) }}</div>
                <div style="margin-top:.4rem;">
                    @foreach ($snap['accounts'] as $acc)
                        <div class="fd-mini"><span>{{ $acc['name'] }}</span><span>{{ $eur($acc['balance']) }}</span></div>
                    @endforeach
                </div>
            </div>
            <div class="fd-card">
                <div class="fd-lbl">Cassa {{ $year }} (entrate − uscite)</div>
                <div class="fd-val {{ $gt['cassa'] >= 0 ? 'fd-pos' : 'fd-neg' }}">{{ $eur($gt['cassa']) }}</div>
                <div class="fd-sub">{{ $eur($gt['entrate']) }} entrate · {{ $eur($gt['uscite']) }} uscite (reali dal conto)</div>
            </div>
            <div class="fd-card">
                <div class="fd-lbl">Crediti (da incassare)</div>
                <div class="fd-val">{{ $eur($snap['crediti']) }}</div>
                <div class="fd-sub">fatture emesse non ancora incassate</div>
            </div>
            <div class="fd-card">
                <div class="fd-lbl">Debiti (da pagare)</div>
                <div class="fd-val fd-neg">{{ $eur($snap['debiti']) }}</div>
                <div class="fd-sub">fatture passive non ancora pagate</div>
            </div>
        </div>

        {{-- Tabella mensile --}}
        <div class="fd-tablewrap" style="margin-top:1.25rem;">
            <table class="fd-table">
                <thead>
                    <tr>
                        <th>Mese</th>
                        <th>Ricavi</th>
                        <th>Costi</th>
                        <th>Margine</th>
                        <th>Entrate</th>
                        <th>Uscite</th>
                        <th>Non attrib.</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($g as $row)
                        @php $vuoto = $row['ricavi'] == 0 && $row['costi'] == 0 && $row['entrate'] == 0 && $row['uscite'] == 0; @endphp
                        <tr class="{{ $vuoto ? 'fd-empty' : '' }}">
                            <td>{{ $row['label'] }}</td>
                            <td>{{ $eur($row['ricavi']) }}</td>
                            <td>{{ $eur($row['costi']) }}</td>
                            <td class="{{ $row['margine'] >= 0 ? 'fd-pos' : 'fd-neg' }}">{{ $eur($row['margine']) }}</td>
                            <td class="fd-pos">{{ $eur($row['entrate']) }}</td>
                            <td class="fd-neg">{{ $eur($row['uscite']) }}</td>
                            <td class="{{ $row['non_attribuite'] > 0 ? 'fd-warn' : '' }}">{{ $eur($row['non_attribuite']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td>Totale {{ $year }}</td>
                        <td>{{ $eur($gt['ricavi']) }}</td>
                        <td>{{ $eur($gt['costi']) }}</td>
                        <td class="{{ $gt['margine'] >= 0 ? 'fd-pos' : 'fd-neg' }}">{{ $eur($gt['margine']) }}</td>
                        <td>{{ $eur($gt['entrate']) }}</td>
                        <td>{{ $eur($gt['uscite']) }}</td>
                        <td class="{{ $gt['non_attribuite'] > 0 ? 'fd-warn' : '' }}">{{ $eur($gt['non_attribuite']) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-filament::section>

    {{-- ==================== GIORGIO GIOTTO — FORFETTARIO ==================== --}}
    <x-filament::section>
        <x-slot name="heading">Giorgio Giotto — P.IVA forfettaria</x-slot>
        <x-slot name="description">Il forfettario tassa l'incassato (non il fatturato) e non deduce i costi.</x-slot>

        {{-- Semaforo limite annuo --}}
        @php
            $perc = min(100, (float) $f['perc_limite']);
            $barColor = $perc < 70 ? '#22c55e' : ($perc < 90 ? '#f59e0b' : '#ef4444');
        @endphp
        <div class="fd-card">
            <div style="display:flex; justify-content:space-between; align-items:baseline;">
                <span class="fd-lbl">Incassato {{ $year }} sul limite di {{ $eur($f['limite']) }}</span>
                <span style="font-weight:700;">{{ $eur($f['incassato_anno']) }} <span style="color:#9ca3af; font-weight:400;">({{ $pct($f['perc_limite']) }})</span></span>
            </div>
            <div class="fd-bartrack"><div class="fd-bar" style="width:{{ $perc }}%; background:{{ $barColor }};"></div></div>
            @if ($perc >= 90)
                <div class="fd-sub fd-neg" style="margin-top:.4rem;">Attenzione: vicino alla soglia — se la superi rischi di uscire dal regime forfettario.</div>
            @endif
        </div>

        {{-- Stima tasse --}}
        <div class="fd-grid" style="margin-top:1rem;">
            <div class="fd-card">
                <div class="fd-lbl">Reddito imponibile</div>
                <div class="fd-val">{{ $eur($f['reddito_lordo']) }}</div>
                <div class="fd-sub">incassato × {{ number_format($f['coefficiente'] * 100, 0) }}%</div>
            </div>
            <div class="fd-card">
                <div class="fd-lbl">INPS stimato</div>
                <div class="fd-val">{{ $eur($f['inps']) }}</div>
                <div class="fd-sub">gestione separata ~{{ number_format($f['inps_rate'] * 100, 2, ',', '.') }}%</div>
            </div>
            <div class="fd-card">
                <div class="fd-lbl">Imposta sostitutiva</div>
                <div class="fd-val">{{ $eur($f['imposta']) }}</div>
                <div class="fd-sub">{{ number_format($f['aliquota'] * 100, 0) }}% sul netto contributi</div>
            </div>
            <div class="fd-card">
                <div class="fd-lbl">Da accantonare</div>
                <div class="fd-val fd-warn">{{ $eur($f['tasse_totali']) }}</div>
                <div class="fd-sub">INPS + imposta · netto stimato {{ $eur($f['netto']) }}</div>
            </div>
        </div>

        {{-- Tabella mensile incassato --}}
        <div class="fd-tablewrap" style="margin-top:1.25rem;">
            <table class="fd-table">
                <thead>
                    <tr><th>Mese</th><th>Incassato</th></tr>
                </thead>
                <tbody>
                    @foreach ($f['months'] as $row)
                        <tr class="{{ $row['incassato'] == 0 ? 'fd-empty' : '' }}">
                            <td>{{ $row['label'] }}</td>
                            <td>{{ $eur($row['incassato']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr><td>Totale {{ $year }}</td><td>{{ $eur($f['incassato_anno']) }}</td></tr>
                </tfoot>
            </table>
        </div>

        <p class="fd-sub" style="margin-top:.75rem;">
            Tieni aggiornate qui le fatture Fiscozen perché i numeri siano corretti. Stime indicative, non sostituiscono il commercialista.
        </p>
    </x-filament::section>
</x-filament-panels::page>
