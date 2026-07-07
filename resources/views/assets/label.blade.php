<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Etichette asset</title>
    <style>
        /* Etichetta tipo Dymo 89 x 36 mm (LabelWriter 99012). Regola @page in
           base al formato caricato; la stampante deve solo stampare. */
        @page {
            size: 89mm 36mm;
            margin: 0;
        }

        * {
            box-sizing: border-box;
        }

        html, body {
            margin: 0;
            padding: 0;
            font-family: Arial, Helvetica, sans-serif;
            color: #000;
            background: #fff;
            -webkit-font-smoothing: antialiased;
            text-rendering: geometricPrecision;
        }

        .label {
            width: 89mm;
            height: 36mm;
            /* Gutter destro piu' ampio: le stampanti per etichette hanno un
               bordo fisico non stampabile (~1.5-3mm). Con il QR a filo del bordo
               destro quella striscia veniva tagliata. Il padding destro maggiore
               tiene il QR dentro l'area stampabile. */
            padding: 2mm 6mm 2mm 4mm;
            display: flex;
            align-items: center;
            gap: 3mm;
            page-break-after: always;
            overflow: hidden;
        }

        .label:last-child {
            page-break-after: auto;
        }

        .label__info {
            flex: 1 1 auto;
            min-width: 0;
            /* Colonna centrata verticalmente: distribuisce lo spazio in modo
               uniforme e mantiene un ritmo verticale pulito. */
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 1mm;
            height: 100%;
        }

        .label__company {
            font-size: 9.5pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            line-height: 1.1;
            /* Nome cliente a capo su piu' righe invece dei puntini di
               sospensione quando e' troppo lungo. Sillabazione automatica
               (lang="it") per ritorni a capo piu' puliti; overflow-wrap:
               anywhere come rete di sicurezza per token senza spazi. */
            white-space: normal;
            overflow-wrap: anywhere;
            hyphens: auto;
        }

        .label__name {
            font-size: 8pt;
            color: #555;
            /* Nome dispositivo a capo con sillabazione, coerente col cliente. */
            white-space: normal;
            overflow-wrap: anywhere;
            hyphens: auto;
            line-height: 1.15;
        }

        .label__barcode img {
            width: 100%;
            height: 9mm;
            object-fit: contain;
            display: block;
        }

        .label__code {
            font-size: 9pt;
            font-weight: 700;
            font-family: "Courier New", monospace;
            letter-spacing: 0.5px;
        }

        .label__qr {
            flex: 0 0 auto;
        }

        .label__qr img {
            width: 16mm;
            height: 16mm;
            display: block;
        }

        .toolbar {
            padding: 16px;
            text-align: center;
            font-family: Arial, sans-serif;
        }

        .toolbar button {
            appearance: none;
            border: none;
            background: #111;
            color: #fff;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.3px;
            padding: 9px 18px;
            border-radius: 8px;
            cursor: pointer;
        }

        .toolbar button:hover {
            background: #333;
        }

        @media print {
            .toolbar {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button onclick="window.print()">Stampa etichette</button>
    </div>

    @foreach ($labels as $label)
        <div class="label">
            <div class="label__info">
                <div class="label__company">{{ $label['company'] }}</div>
                @if (! empty($label['name']))
                    <div class="label__name">{{ $label['name'] }}</div>
                @endif
                <div class="label__barcode">
                    <img src="{{ $label['barcode'] }}" alt="barcode">
                </div>
                <div class="label__code">{{ $label['asset_code'] }}</div>
            </div>
            <div class="label__qr">
                <img src="{{ $label['qr'] }}" alt="qr">
            </div>
        </div>
    @endforeach

    <script>
        window.addEventListener('load', function () {
            window.print();
        });
    </script>
</body>
</html>
