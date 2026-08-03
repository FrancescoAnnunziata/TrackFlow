@php
    use App\Models\Quote;

    $euro = fn ($valore) => '€ ' . number_format((float) $valore, 2, ',', '.');
    // Chi emette questo preventivo: dà nome e logo anche alla cornice attorno
    // al documento, non solo al documento stesso.
    $emittente = $quote->emittente();
    $logo = $emittente->logoDataUri();
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Preventivo {{ $quote->number }} — {{ $emittente->nome() }}</title>
    @if ($logo)
        <link rel="icon" href="{{ $logo }}">
    @endif
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #eef2f6;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #1f2937;
            -webkit-text-size-adjust: 100%;
        }

        .topbar {
            background: #111827;
            color: #e5e7eb;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .topbar .brand { display: flex; align-items: center; gap: 10px; font-weight: 600; font-size: 14px; }
        .topbar img { height: 24px; background: #fff; border-radius: 4px; padding: 2px 4px; }
        .topbar .actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

        .badge { font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 999px; white-space: nowrap; }
        .badge.sent { background: #fef3c7; color: #92400e; }
        .badge.accepted { background: #dcfce7; color: #166534; }
        .badge.rejected { background: #fee2e2; color: #991b1b; }
        .badge.other { background: #e5e7eb; color: #374151; }

        .wrap { max-width: 860px; margin: 0 auto; padding: 24px 16px 64px; }

        .sheet {
            background: #fff;
            border-radius: 6px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.12);
            padding: 44px 46px;
        }

        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 6px 20px rgba(15, 23, 42, 0.08);
            padding: 24px;
            margin-top: 24px;
        }
        .card h2 { margin: 0 0 4px; font-size: 17px; color: #111827; }
        .card .sub { margin: 0 0 18px; font-size: 13px; color: #6b7280; }

        .field { margin-bottom: 14px; }
        .field label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 5px; }
        .field input[type="text"], .field textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font: inherit;
            font-size: 14px;
            background: #fff;
        }
        .field input[type="text"]:focus, .field textarea:focus { outline: 2px solid #f59e0b; outline-offset: -1px; border-color: #f59e0b; }
        .row { display: flex; gap: 14px; flex-wrap: wrap; }
        .row .field { flex: 1 1 220px; }

        .pad-shell { border: 2px dashed #cbd5e1; border-radius: 10px; background: #f8fafc; position: relative; touch-action: none; }
        .pad-shell.filled { border-style: solid; border-color: #f59e0b; background: #fff; }
        canvas#pad { display: block; width: 100%; height: 190px; border-radius: 10px; cursor: crosshair; touch-action: none; }
        .pad-hint {
            position: absolute; inset: 0;
            display: flex; align-items: center; justify-content: center;
            color: #94a3b8; font-size: 14px; pointer-events: none; text-align: center; padding: 0 16px;
        }
        .pad-shell.filled .pad-hint { display: none; }
        .pad-foot { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 8px; flex-wrap: wrap; }
        .pad-foot .note { font-size: 12px; color: #6b7280; }

        .check { display: flex; gap: 10px; align-items: flex-start; margin: 18px 0; font-size: 13px; color: #374151; line-height: 1.5; }
        .check input { margin-top: 3px; width: 17px; height: 17px; accent-color: #f59e0b; flex: none; }

        button, .btn {
            font: inherit;
            font-size: 14px;
            font-weight: 600;
            border-radius: 8px;
            border: 1px solid transparent;
            padding: 11px 20px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        .btn-primary { background: #f59e0b; color: #111827; }
        .btn-primary:hover { background: #d97706; }
        .btn-ghost { background: #fff; color: #374151; border-color: #d1d5db; }
        .btn-ghost:hover { background: #f9fafb; }
        .btn-dark { background: #374151; color: #fff; }
        .btn-dark:hover { background: #1f2937; }
        .btn-danger { background: #fff; color: #b91c1c; border-color: #fca5a5; }
        .btn-danger:hover { background: #fef2f2; }
        .btn-sm { padding: 7px 14px; font-size: 13px; }
        button[disabled] { opacity: 0.5; cursor: not-allowed; }

        .alert { border-radius: 10px; padding: 14px 16px; font-size: 14px; margin-bottom: 18px; }
        .alert-success { background: #dcfce7; color: #14532d; border: 1px solid #86efac; }
        .alert-info { background: #e0f2fe; color: #075985; border: 1px solid #7dd3fc; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .alert ul { margin: 6px 0 0; padding-left: 18px; }

        details.reject { margin-top: 18px; border-top: 1px solid #e5e7eb; padding-top: 16px; }
        details.reject summary { cursor: pointer; font-size: 13px; color: #6b7280; }
        details.reject[open] summary { margin-bottom: 12px; }

        .done { display: flex; gap: 16px; align-items: flex-start; flex-wrap: wrap; }
        .done .txt { flex: 1 1 260px; }

        @media (max-width: 640px) {
            .sheet { padding: 24px 18px; }
            .wrap { padding: 16px 10px 48px; }
        }

        @media print {
            body { background: #fff; }
            .topbar, .card, .alert { display: none !important; }
            .sheet { box-shadow: none; padding: 0; }
            .wrap { max-width: none; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="topbar">
        <div class="brand">
            @if ($logo)
                <img src="{{ $logo }}" alt="">
            @endif
            <span>{{ $emittente->nome() }}</span>
        </div>
        <div class="actions">
            @php
                $badge = match ($quote->status) {
                    Quote::STATUS_SENT => ['sent', 'In attesa di firma'],
                    Quote::STATUS_ACCEPTED => ['accepted', 'Accettato e firmato'],
                    Quote::STATUS_INVOICED => ['accepted', 'Accettato e fatturato'],
                    Quote::STATUS_REJECTED => ['rejected', 'Rifiutato'],
                    default => ['other', 'Bozza'],
                };
            @endphp
            <span class="badge {{ $badge[0] }}">{{ $badge[1] }}</span>
            @if ($quote->isSigned())
                <a class="btn btn-ghost btn-sm" href="{{ route('quote.pdf', $quote) }}">Scarica il PDF</a>
            @endif
            @if ($isAdmin)
                <a class="btn btn-ghost btn-sm" href="{{ $panelUrl }}">Torna al pannello</a>
            @endif
        </div>
    </div>

    <div class="wrap">
        @if (session('quote_status'))
            <div class="alert alert-success">{{ session('quote_status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error">
                Controlla i dati inseriti:
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($isAdmin && $quote->awaitsDecision())
            <div class="alert alert-info">
                Stai vedendo il documento come lo vede il cliente. La firma può apporla solo un referente di
                {{ $quote->client->name }}.
            </div>
        @endif

        <div class="sheet">
            @include('quotes.partials.document', ['quote' => $quote])
        </div>

        @if ($canSign)
            <div class="card">
                <h2>Accetta e firma</h2>
                <p class="sub">Firma con il dito o con il mouse nel riquadro qui sotto, poi invia. Riceverai subito una copia in PDF via email.</p>

                <form method="POST" action="{{ route('quote.sign', $quote) }}" id="sign-form">
                    @csrf

                    <div class="row">
                        <div class="field">
                            <label for="signer_name">Nome e cognome di chi firma</label>
                            <input type="text" id="signer_name" name="signer_name" maxlength="120" required
                                   value="{{ old('signer_name', trim($user->name . ' ' . $user->surname)) }}">
                        </div>
                        <div class="field">
                            <label for="signer_role">Qualifica <span style="font-weight: 400; color: #9ca3af;">(facoltativa)</span></label>
                            <input type="text" id="signer_role" name="signer_role" maxlength="120"
                                   value="{{ old('signer_role') }}" placeholder="es. Amministratore">
                        </div>
                    </div>

                    <div class="field">
                        <label>Firma</label>
                        <div class="pad-shell" id="pad-shell">
                            <canvas id="pad"></canvas>
                            <div class="pad-hint">Firma qui — usa il dito su mobile, il mouse su computer</div>
                        </div>
                        <div class="pad-foot">
                            <span class="note">Tratto libero: la firma viene allegata al documento e al PDF.</span>
                            <button type="button" class="btn-ghost btn-sm" id="clear-pad">Cancella firma</button>
                        </div>
                    </div>

                    <label class="check">
                        <input type="checkbox" name="accept" value="1" id="accept" {{ old('accept') ? 'checked' : '' }}>
                        <span>
                            Dichiaro di aver letto il preventivo <strong>{{ $quote->number }}</strong> e di accettarlo
                            integralmente per un totale di <strong>{{ $euro($quote->total()) }}</strong> (IVA inclusa),
                            alle condizioni indicate nel documento.
                        </span>
                    </label>

                    <input type="hidden" name="signature" id="signature">

                    <button type="submit" class="btn-primary" id="submit-sign">Firma e invia</button>

                    <details class="reject">
                        <summary>Non vuoi accettarlo?</summary>
                    </details>
                </form>

                <form method="POST" action="{{ route('quote.reject', $quote) }}" id="reject-form" style="display: none;">
                    @csrf
                    <div class="field">
                        <label for="rejection_reason">Motivo del rifiuto <span style="font-weight: 400; color: #9ca3af;">(facoltativo)</span></label>
                        <textarea id="rejection_reason" name="rejection_reason" rows="3" maxlength="1000"
                                  placeholder="Se vuoi, spiega perché: aiuta a proporti un'alternativa.">{{ old('rejection_reason') }}</textarea>
                    </div>
                    <button type="submit" class="btn-danger">Rifiuta il preventivo</button>
                </form>
            </div>
        @elseif ($quote->isSigned())
            <div class="card">
                <div class="done">
                    <div class="txt">
                        <h2>Preventivo accettato</h2>
                        <p class="sub" style="margin-bottom: 0;">
                            Firmato da {{ $quote->signer_name }} il
                            {{ $quote->accepted_at?->format('d/m/Y') }} alle {{ $quote->accepted_at?->format('H:i') }}.
                            La copia in PDF è stata inviata via email a entrambe le parti.
                        </p>
                    </div>
                    <a class="btn btn-dark" href="{{ route('quote.pdf', $quote) }}">Scarica il PDF</a>
                </div>
            </div>
        @elseif ($quote->status === Quote::STATUS_REJECTED)
            <div class="card">
                <h2>Preventivo rifiutato</h2>
                <p class="sub" style="margin-bottom: 0;">
                    Rifiutato il {{ $quote->rejected_at?->format('d/m/Y') }} alle {{ $quote->rejected_at?->format('H:i') }}.
                    @if (filled($quote->rejection_reason))
                        Motivo indicato: {{ $quote->rejection_reason }}
                    @endif
                </p>
            </div>
        @elseif ($quote->status === Quote::STATUS_DRAFT)
            <div class="card">
                <h2>Preventivo in bozza</h2>
                <p class="sub" style="margin-bottom: 0;">Non è ancora stato inviato al cliente, quindi non è firmabile.</p>
            </div>
        @endif
    </div>

    @if ($canSign)
    <script>
        (function () {
            const shell = document.getElementById('pad-shell');
            const canvas = document.getElementById('pad');
            const ctx = canvas.getContext('2d');
            const form = document.getElementById('sign-form');
            const hidden = document.getElementById('signature');
            const accept = document.getElementById('accept');
            const submit = document.getElementById('submit-sign');

            let drawing = false;
            let hasStrokes = false;
            // I tratti restano in memoria per poterli ridisegnare al resize:
            // il canvas si svuota ogni volta che cambia la sua dimensione.
            let strokes = [];
            let current = null;

            function setupCanvas() {
                const ratio = window.devicePixelRatio || 1;
                const width = canvas.clientWidth;
                const height = canvas.clientHeight;

                canvas.width = Math.round(width * ratio);
                canvas.height = Math.round(height * ratio);

                ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
                ctx.lineWidth = 2.4;
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';
                ctx.strokeStyle = '#0f172a';

                redraw();
            }

            function redraw() {
                ctx.clearRect(0, 0, canvas.clientWidth, canvas.clientHeight);

                for (const stroke of strokes) {
                    if (stroke.length < 2) {
                        continue;
                    }
                    ctx.beginPath();
                    ctx.moveTo(stroke[0].x, stroke[0].y);
                    for (let i = 1; i < stroke.length; i++) {
                        ctx.lineTo(stroke[i].x, stroke[i].y);
                    }
                    ctx.stroke();
                }
            }

            function pointFrom(event) {
                const rect = canvas.getBoundingClientRect();

                return { x: event.clientX - rect.left, y: event.clientY - rect.top };
            }

            function start(event) {
                event.preventDefault();
                drawing = true;
                current = [pointFrom(event)];
                strokes.push(current);
                canvas.setPointerCapture(event.pointerId);
            }

            function move(event) {
                if (!drawing) {
                    return;
                }
                event.preventDefault();

                const point = pointFrom(event);
                const previous = current[current.length - 1];

                current.push(point);

                ctx.beginPath();
                ctx.moveTo(previous.x, previous.y);
                ctx.lineTo(point.x, point.y);
                ctx.stroke();

                if (!hasStrokes) {
                    hasStrokes = true;
                    shell.classList.add('filled');
                }
            }

            function end(event) {
                if (!drawing) {
                    return;
                }
                event.preventDefault();
                drawing = false;
                // Un tocco singolo (punto) non produce linea: lo rendo visibile.
                if (current.length === 1) {
                    const point = current[0];
                    ctx.beginPath();
                    ctx.arc(point.x, point.y, 1.2, 0, Math.PI * 2);
                    ctx.fillStyle = '#0f172a';
                    ctx.fill();
                    hasStrokes = true;
                    shell.classList.add('filled');
                }
                current = null;
            }

            canvas.addEventListener('pointerdown', start);
            canvas.addEventListener('pointermove', move);
            canvas.addEventListener('pointerup', end);
            canvas.addEventListener('pointercancel', end);
            canvas.addEventListener('pointerleave', end);

            document.getElementById('clear-pad').addEventListener('click', function () {
                strokes = [];
                hasStrokes = false;
                shell.classList.remove('filled');
                redraw();
            });

            let resizeTimer = null;
            window.addEventListener('resize', function () {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(setupCanvas, 150);
            });

            form.addEventListener('submit', function (event) {
                if (!hasStrokes) {
                    event.preventDefault();
                    alert('Disegna la tua firma nel riquadro prima di inviare.');

                    return;
                }
                if (!accept.checked) {
                    event.preventDefault();
                    alert('Spunta la dichiarazione di accettazione per procedere.');

                    return;
                }

                hidden.value = canvas.toDataURL('image/png');
                submit.disabled = true;
                submit.textContent = 'Invio in corso…';
            });

            // Il rifiuto vive in un form separato (campi diversi), ma lo apro
            // dal <details> dentro il form di firma per tenerlo defilato.
            const details = document.querySelector('details.reject');
            const rejectForm = document.getElementById('reject-form');
            details.addEventListener('toggle', function () {
                rejectForm.style.display = details.open ? 'block' : 'none';
            });

            setupCanvas();
        })();
    </script>
    @endif
</body>
</html>
