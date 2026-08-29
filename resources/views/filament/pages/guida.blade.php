<x-filament-panels::page>
    {{--
        La guida ha un CSS proprio (scoped sotto .guida): le utility Tailwind nei
        blade custom non vengono compilate nel bundle del pannello Filament, quindi
        qui lo stile è autoconsistente. Dark mode agganciato alla classe .dark che
        Filament mette su <html> col suo toggle.
    --}}
    <style>
        .guida { --g-fg:#374151; --g-heading:#0b1220; --g-muted:#6b7280; --g-card:#ffffff; --g-border:rgba(2,6,23,.08); --g-shadow:0 1px 2px rgba(2,6,23,.05); --g-soft:#f8fafc; }
        .dark .guida { --g-fg:#cbd5e1; --g-heading:#f8fafc; --g-muted:#94a3b8; --g-card:rgba(255,255,255,.04); --g-border:rgba(255,255,255,.10); --g-shadow:none; --g-soft:rgba(255,255,255,.03); }

        .guida { max-width:52rem; margin:0 auto; display:flex; flex-direction:column; gap:1.5rem; color:var(--g-fg); font-size:.9rem; line-height:1.6; }
        .guida p { margin:0; }
        .guida strong { color:var(--g-heading); font-weight:650; }
        .guida em { font-style:italic; }
        .guida u { text-decoration:underline; text-underline-offset:2px; }

        .g-card { background:var(--g-card); border:1px solid var(--g-border); border-radius:14px; padding:1.4rem 1.5rem; box-shadow:var(--g-shadow); }
        .g-card > * + * { margin-top:.85rem; }

        .g-title { font-size:1.35rem; font-weight:750; color:var(--g-heading); letter-spacing:-.01em; }
        .g-h { font-size:1.1rem; font-weight:700; color:var(--g-heading); }
        .g-sub { font-size:.95rem; font-weight:700; color:var(--g-heading); }
        .g-eyebrow { font-size:.72rem; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--g-muted); }

        .g-head { display:flex; align-items:center; gap:.75rem; }
        .g-num { flex:0 0 auto; width:2rem; height:2rem; border-radius:999px; background:#4f46e5; color:#fff; font-weight:700; font-size:.85rem; display:inline-flex; align-items:center; justify-content:center; }
        .g-num.alt { background:#6366f1; font-size:.72rem; }
        .g-num.gray { background:#64748b; }

        .g-steps, .g-list { margin:0; padding:0; list-style:none; display:flex; flex-direction:column; gap:.55rem; }
        .g-ol { margin:.4rem 0 0; padding-left:1.35rem; display:flex; flex-direction:column; gap:.5rem; }
        .g-ol > li::marker { font-weight:700; color:#4f46e5; }
        .g-overview li { display:flex; align-items:center; gap:.75rem; }
        .g-list li { position:relative; padding-left:1.1rem; }
        .g-list li::before { content:"•"; position:absolute; left:.15rem; color:#4f46e5; font-weight:700; }
        .g-list.tight li + li { margin-top:0; }
        .g-sublist { margin:.35rem 0 0 .6rem; padding-left:1rem; display:flex; flex-direction:column; gap:.25rem; }
        .g-sublist li::before { content:"—"; margin-right:.35rem; color:var(--g-muted); }

        .g-callout { border-radius:11px; padding:.9rem 1rem; font-size:.86rem; line-height:1.55; border:1px solid transparent; }
        .g-info   { background:#eff6ff; color:#1e40af; border-color:rgba(37,99,235,.18); }
        .g-warn   { background:#fffbeb; color:#92400e; border-color:rgba(217,119,6,.2); }
        .g-danger { background:#fef2f2; color:#991b1b; border-color:rgba(220,38,38,.2); }
        .g-ok     { background:#f0fdf4; color:#166534; border-color:rgba(22,163,74,.2); }
        .g-note   { background:#faf5ff; color:#6b21a8; border-color:rgba(147,51,234,.2); }
        .dark .g-info   { background:rgba(59,130,246,.12); color:#93c5fd; }
        .dark .g-warn   { background:rgba(245,158,11,.12); color:#fcd34d; }
        .dark .g-danger { background:rgba(239,68,68,.12); color:#fca5a5; }
        .dark .g-ok     { background:rgba(34,197,94,.12); color:#86efac; }
        .dark .g-note   { background:rgba(168,85,247,.12); color:#d8b4fe; }
        .g-callout strong { color:inherit; }

        .g-channels { display:grid; gap:.9rem; grid-template-columns:1fr; }
        @media (min-width:640px){ .g-channels { grid-template-columns:1fr 1fr; } }
        .g-channel { border-radius:11px; padding:.9rem 1rem; border:1px solid transparent; }
        .g-channel .g-ct { font-weight:700; font-size:.9rem; margin-bottom:.25rem; }
        .g-channel.green  { background:#f0fdf4; border-color:rgba(22,163,74,.22); color:#166534; }
        .g-channel.purple { background:#faf5ff; border-color:rgba(147,51,234,.22); color:#6b21a8; }
        .dark .g-channel.green  { background:rgba(34,197,94,.1); color:#86efac; }
        .dark .g-channel.purple { background:rgba(168,85,247,.1); color:#d8b4fe; }
        .g-channel .g-ct { color:inherit; }
        .g-channel strong { color:inherit; }

        .g-block { border-radius:11px; padding:.9rem 1rem; border:1px solid transparent; }
        .g-block.green  { background:#f0fdf4; border-color:rgba(22,163,74,.22); }
        .g-block.purple { background:#faf5ff; border-color:rgba(147,51,234,.22); }
        .dark .g-block.green  { background:rgba(34,197,94,.08); }
        .dark .g-block.purple { background:rgba(168,85,247,.08); }
        .g-block .g-bt { font-weight:700; font-size:.88rem; margin-bottom:.4rem; }
        .g-block.green  .g-bt, .g-block.green  .g-ol > li::marker  { color:#166534; }
        .g-block.purple .g-bt, .g-block.purple .g-ol > li::marker  { color:#6b21a8; }
        .dark .g-block.green .g-bt  { color:#86efac; }
        .dark .g-block.purple .g-bt { color:#d8b4fe; }

        .g-tablewrap { overflow-x:auto; margin-top:.25rem; }
        .g-table { width:100%; border-collapse:collapse; font-size:.84rem; }
        .g-table th { text-align:left; font-size:.7rem; text-transform:uppercase; letter-spacing:.04em; color:var(--g-muted); font-weight:600; padding:.4rem .6rem; border-bottom:1px solid var(--g-border); }
        .g-table td { padding:.5rem .6rem; border-bottom:1px solid var(--g-border); vertical-align:top; }
        .g-table tr:last-child td { border-bottom:none; }
        .g-table td.name { color:var(--g-heading); font-weight:600; }

        .g-badge { display:inline-flex; align-items:center; border-radius:6px; padding:.05rem .4rem; font-size:.72rem; font-weight:700; }
        .g-badge.green  { background:#dcfce7; color:#15803d; }
        .g-badge.purple { background:#f3e8ff; color:#7e22ce; }
        .g-badge.amber  { background:#fef3c7; color:#b45309; }
        .g-badge.gray   { background:#f1f5f9; color:#475569; }
        .dark .g-badge.green  { background:rgba(34,197,94,.14); color:#86efac; }
        .dark .g-badge.purple { background:rgba(168,85,247,.14); color:#d8b4fe; }
        .dark .g-badge.amber  { background:rgba(245,158,11,.14); color:#fcd34d; }
        .dark .g-badge.gray   { background:rgba(148,163,184,.16); color:#cbd5e1; }

        .g-kbd { display:inline-block; border-radius:6px; background:var(--g-soft); border:1px solid var(--g-border); padding:.05rem .35rem; font-size:.8em; font-weight:600; color:var(--g-heading); white-space:nowrap; }
        .g-muted { color:var(--g-muted); font-size:.8rem; }
        .g-foot { text-align:center; color:var(--g-muted); font-size:.75rem; padding-bottom:1rem; }
        .g-status { display:flex; align-items:center; gap:.6rem; }
    </style>

    <div class="guida">
        @include('filament.manuale-contenuto')
    </div>
</x-filament-panels::page>
