<x-layout title="Dettaglio ore">
    <div class="mb-8 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-foreground">Dettaglio ore</h1>
            <p class="mt-1 text-sm text-muted-foreground">Riepilogo della registrazione selezionata.</p>
        </div>
        <a
            href="/hours"
            class="py-2.5 px-5 inline-flex items-center gap-x-2 text-sm font-medium rounded-lg border border-layer-line bg-layer text-foreground hover:bg-layer-hover focus:outline-none focus:bg-layer-hover transition-colors"
        >
            Torna alla lista
        </a>
    </div>

    <div class="bg-layer border border-layer-line rounded-xl shadow-sm p-6 sm:p-8 space-y-5">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <p class="text-xs uppercase tracking-wide text-muted-foreground">Data</p>
                <p class="mt-1 text-sm text-foreground">{{ \Carbon\Carbon::parse($hour->date)->locale('it')->translatedFormat('d F Y') }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-muted-foreground">Ore</p>
                <p class="mt-1 text-sm text-foreground">{{ $hour->hours }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-muted-foreground">Cliente</p>
                <p class="mt-1 text-sm text-foreground">#{{ $hour->client_id }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-muted-foreground">Fatturabile</p>
                <p class="mt-1 text-sm text-foreground">{{ $hour->billable ? 'Si' : 'No' }}</p>
            </div>
        </div>

        <div class="border-t border-layer-line pt-5">
            <p class="text-xs uppercase tracking-wide text-muted-foreground">Note</p>
            <p class="mt-1 text-sm text-foreground whitespace-pre-line">{{ $hour->notes ?: 'Nessuna nota disponibile.' }}</p>
        </div>

        <div class="border-t border-layer-line pt-5 flex items-center justify-end gap-3">
            <a
                href="/hours"
                class="py-2.5 px-5 inline-flex items-center gap-x-2 text-sm font-medium rounded-lg border border-layer-line bg-layer text-foreground hover:bg-layer-hover focus:outline-none focus:bg-layer-hover transition-colors"
            >
                Chiudi
            </a>
            <a
                href="/hours/{{ $hour->id }}/edit"
                class="py-2.5 px-5 inline-flex items-center gap-x-2 text-sm font-semibold rounded-lg border border-transparent bg-primary text-primary-foreground hover:bg-primary-hover focus:outline-none focus:bg-primary-hover transition-colors"
            >
                Modifica
            </a>
        </div>
    </div>
</x-layout>

