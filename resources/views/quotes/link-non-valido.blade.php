@php
    use App\Models\Quote;
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Link non più valido</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eef2f6;
            color: #1f2937;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            padding: 24px;
        }
        .card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12);
            padding: 32px;
            max-width: 460px;
            width: 100%;
        }
        h1 { font-size: 19px; margin: 0 0 10px; }
        p { font-size: 14px; line-height: 1.6; color: #4b5563; margin: 0 0 12px; }
        .hint { font-size: 13px; color: #6b7280; background: #f9fafb; border-left: 3px solid #d1d5db; padding: 10px 12px; border-radius: 0 8px 8px 0; }
        a.btn {
            display: inline-block;
            margin-top: 18px;
            background: #f59e0b;
            color: #111827;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            padding: 11px 20px;
            border-radius: 8px;
        }
        a.btn:hover { background: #d97706; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Questo link non è più valido</h1>
        <p>
            I link di accesso ai preventivi restano attivi {{ Quote::MAGIC_LINK_DAYS }} giorni dall'invio.
            Se il tuo è scaduto, apri l'email più recente che hai ricevuto: contiene sempre un link nuovo.
        </p>
        <p class="hint">
            Non trovi l'email o il preventivo ti serve ancora? Rispondi all'ultimo messaggio ricevuto
            e te lo reinviamo con un link valido.
        </p>
        <a class="btn" href="{{ url('/login') }}">Ho un account, accedi</a>
    </div>
</body>
</html>
