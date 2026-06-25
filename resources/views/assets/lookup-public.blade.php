<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dispositivo inventariato</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: Arial, Helvetica, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            padding: 24px;
        }

        .card {
            background: #1e293b;
            border-radius: 16px;
            padding: 32px;
            max-width: 420px;
            width: 100%;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
        }

        .card h1 {
            font-size: 18px;
            margin: 0 0 8px;
        }

        .card .company {
            font-size: 15px;
            color: #94a3b8;
            margin-bottom: 20px;
        }

        .card .code {
            font-family: "Courier New", monospace;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 1px;
            background: #0f172a;
            border-radius: 8px;
            padding: 12px;
            display: inline-block;
        }

        .card .hint {
            margin-top: 22px;
            font-size: 13px;
            color: #64748b;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <div class="card">
        @if ($device)
            <h1>Dispositivo inventariato G8Labs</h1>
            <div class="company">{{ $device->client?->name }}</div>
            <div class="code">{{ $device->asset_code }}</div>
            <p class="hint">
                Questo dispositivo è registrato nell'inventario aziendale.<br>
                Per la scheda completa accedi al gestionale con le tue credenziali.
            </p>
        @else
            <h1>Dispositivo non trovato</h1>
            <p class="hint">Il codice scansionato non corrisponde a nessun dispositivo inventariato.</p>
        @endif
    </div>
</body>
</html>
