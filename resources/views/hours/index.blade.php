<x-layout title="Ore">
    <div class="max-w-2xl mx-auto px-4 py-10">

        {{-- Intestazione pagina --}}
        <div class="mb-8 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-foreground">Ore</h1>
                <p class="mt-1 text-sm text-muted-foreground">Inserisci le ore lavorate.</p>
            </div>
            <a
                href="/hours/create"
                class="py-2.5 px-5 inline-flex items-center gap-x-2 text-sm font-semibold rounded-lg border border-transparent bg-primary text-primary-foreground hover:bg-primary-hover focus:outline-none focus:bg-primary-hover disabled:opacity-50 disabled:pointer-events-none transition-colors"
            >
                <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                <span>Aggiungi</span>
            </a>
        </div>

        {{-- Elenco ore --}}
        <div class="mt-8 bg-layer border border-layer-line rounded-xl shadow-sm p-6 sm:p-8">
            <h2 class="text-lg font-semibold text-foreground">Ore registrate</h2>

            @if($hours->count())
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="border-b border-layer-line text-muted-foreground">
                                <th class="py-2 pr-4 font-medium">Data</th>
                                <th class="py-2 pr-4 font-medium">Ore</th>
                                <th class="py-2 pr-4 font-medium">Cliente</th>
                                <th class="py-2 pr-4 font-medium">Fatturabile</th>
                                <th class="py-2 font-medium">Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($hours as $hour)
                                <tr class="border-b border-layer-line last:border-b-0 text-foreground">
                                    <td class="py-3 pr-4">{{ \Carbon\Carbon::parse($hour->date)->locale('it')->translatedFormat('d F Y') }}</td>
                                    <td class="py-3 pr-4">{{ $hour->hours }}</td>
                                    <td class="py-3 pr-4">#{{ $hour->client_id }}</td>
                                    <td class="py-3 pr-4">{{ $hour->billable ? 'Sì' : 'No' }}</td>
                                    <td class="py-3">
                                        <p>{{ $hour->notes ?: '—' }}</p>
                                        <div class="mt-2 flex items-center gap-2">
                                            <a
                                                href="/hours/{{ $hour->id }}"
                                                class="px-2.5 py-1 text-xs font-medium rounded-md border border-layer-line bg-layer text-foreground hover:bg-layer-hover transition-colors"
                                            >
                                                Dettaglio
                                            </a>
                                            <a
                                                href="/hours/{{ $hour->id }}/edit"
                                                class="px-2.5 py-1 text-xs font-medium rounded-md border border-layer-line bg-layer text-foreground hover:bg-layer-hover transition-colors"
                                            >
                                                Modifica
                                            </a>
                                            <form action="/hours/{{ $hour->id }}" method="POST" onsubmit="return confirm('Eliminare questa registrazione?');" class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <button
                                                    type="submit"
                                                    class="px-2.5 py-1 text-xs font-medium rounded-md border border-red-300 text-red-600 hover:bg-red-50 transition-colors"
                                                >
                                                    Elimina
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="mt-4 text-sm text-muted-foreground">Non ci sono ore disponibili.</p>
            @endif
        </div>
    </div>
</x-layout>
