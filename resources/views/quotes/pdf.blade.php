<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Preventivo {{ $quote->number }}</title>
    <style>
        @page { margin: 34px 40px 46px; }
        body { margin: 0; }

        /* Piè di pagina ripetuto su ogni foglio; dompdf risolve counter(page). */
        .pdf-footer {
            position: fixed;
            bottom: -26px;
            left: 0;
            right: 0;
            font-family: DejaVu Sans, sans-serif;
            font-size: 8px;
            color: #9ca3af;
            border-top: 1px solid #e5e7eb;
            padding-top: 4px;
        }
        .pdf-footer .right { float: right; }
        .pdf-footer .pagenum:after { content: counter(page); }
    </style>
</head>
<body>
    <div class="pdf-footer">
        Preventivo {{ $quote->number }} — {{ $quote->client->name }}
        <span class="right">Pagina <span class="pagenum"></span></span>
    </div>

    @include('quotes.partials.document', ['quote' => $quote])
</body>
</html>
