<x-layout title="Ore">
    <div class="max-w-2xl mx-auto px-4 py-10">

        {{-- Intestazione pagina --}}
        <div class="mb-8 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-foreground">Ore</h1>
                <p class="mt-1 text-sm text-muted-foreground">Inserisci le ore lavorate.</p>
            </div>
            <button
                type="button"
                id="toggleFormBtn"
                onclick="toggleHourForm()"
                class="py-2.5 px-5 inline-flex items-center gap-x-2 text-sm font-semibold rounded-lg border border-transparent bg-primary text-primary-foreground hover:bg-primary-hover focus:outline-none focus:bg-primary-hover disabled:opacity-50 disabled:pointer-events-none transition-colors"
            >
                <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                <span id="btnText">Aggiungi</span>
            </button>
        </div>

        {{-- Card form --}}
        <div id="hourFormCard" class="bg-layer border border-layer-line rounded-xl shadow-sm p-6 sm:p-8 hidden">
            <form action="/hours" method="POST" class="space-y-5">
                @csrf

                {{-- Data --}}
                <div>
                    <label for="date" class="block text-sm font-medium text-foreground mb-1.5">
                        Data
                    </label>
                    <input
                        type="date"
                        id="date"
                        name="date"
                        value="{{ date('Y-m-d') }}"
                        required
                        class="py-2.5 px-4 block w-full rounded-lg border border-layer-line bg-layer text-foreground text-sm placeholder:text-muted-foreground focus:border-primary focus:ring-2 focus:ring-primary/30 disabled:opacity-50 disabled:pointer-events-none"
                    />
                </div>

                {{-- Ore --}}
                <div>
                    <label for="hours" class="block text-sm font-medium text-foreground mb-1.5">
                        Ore lavorate
                    </label>
                    <input
                        type="number"
                        id="hours"
                        name="hours"
                        min="0"
                        step="0.5"
                        required
                        class="py-2.5 px-4 block w-full rounded-lg border border-layer-line bg-layer text-foreground text-sm placeholder:text-muted-foreground focus:border-primary focus:ring-2 focus:ring-primary/30 disabled:opacity-50 disabled:pointer-events-none"
                        placeholder="Iserisci il numero di ore da registrare"
                    />
                </div>

                {{-- Cliente --}}
                <div>
                    <label for="client_id" class="block text-sm font-medium text-foreground mb-1.5">
                        Cliente
                    </label>
                    <select
                        id="client_id"
                        name="client_id"
                        required
                        class="py-2.5 px-4 block w-full rounded-lg border border-layer-line bg-layer text-foreground text-sm focus:border-primary focus:ring-2 focus:ring-primary/30 disabled:opacity-50 disabled:pointer-events-none"
                    >
                        <option value="">— Seleziona cliente —</option>
                        <option value="1">Cliente Example 1</option>
                        <option value="2">Cliente Example 2</option>
                    </select>
                </div>

                {{-- Note --}}
                <div>
                    <label for="notes" class="block text-sm font-medium text-foreground mb-1.5">
                        Note
                        <span class="text-xs font-normal text-muted-foreground ms-1">(opzionale)</span>
                    </label>
                    <textarea
                        id="notes"
                        name="notes"
                        rows="3"
                        class="py-2.5 px-4 block w-full rounded-lg border border-layer-line bg-layer text-foreground text-sm placeholder:text-muted-foreground focus:border-primary focus:ring-2 focus:ring-primary/30 disabled:opacity-50 disabled:pointer-events-none"
                        placeholder="Descrivi brevemente l'attività svolta..."
                    ></textarea>
                </div>

                {{-- Billable toggle --}}
                <div class="flex items-center justify-between p-4 rounded-lg border border-layer-line bg-layer-hover">
                    <div>
                        <span class="block text-sm font-medium text-foreground">Fatturabile</span>
                        <span class="block text-xs text-muted-foreground mt-0.5">Segna queste ore come fatturabili al cliente</span>
                    </div>
                    <label class="relative inline-block w-11 h-6 cursor-pointer">
                        <input
                            type="checkbox"
                            id="billable"
                            name="billable"
                            value="1"
                            class="peer sr-only"
                        />
                        <span
                            class="absolute inset-0 rounded-full bg-layer-line transition-colors duration-200 peer-checked:bg-primary"></span>
                        <span
                            class="absolute top-0.5 left-0.5 size-5 rounded-full bg-white shadow transition-transform duration-200 peer-checked:translate-x-5"></span>
                    </label>
                </div>

                {{-- Divider --}}
                <div class="border-t border-layer-line"></div>

                {{-- Submit --}}
                <div class="flex items-center justify-end gap-3 pt-1">
                    <a
                        href="/"
                        class="py-2.5 px-5 inline-flex items-center gap-x-2 text-sm font-medium rounded-lg border border-layer-line bg-layer text-foreground hover:bg-layer-hover focus:outline-none focus:bg-layer-hover transition-colors"
                    >
                        Annulla
                    </a>
                    <button
                        type="submit"
                        class="py-2.5 px-5 inline-flex items-center gap-x-2 text-sm font-semibold rounded-lg border border-transparent bg-primary text-primary-foreground hover:bg-primary-hover focus:outline-none focus:bg-primary-hover disabled:opacity-50 disabled:pointer-events-none transition-colors"
                    >
                        <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                            <polyline points="17 21 17 13 7 13 7 21"/>
                            <polyline points="7 3 7 8 15 8"/>
                        </svg>
                        Salva ore
                    </button>
                </div>

            </form>
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
                                    <td class="py-3">{{ $hour->notes ?: '—' }}</td>
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

    <script>
        function toggleHourForm() {
            const formCard = document.getElementById('hourFormCard');
            const btnText = document.getElementById('btnText');
            const btn = document.getElementById('toggleFormBtn');
            const icon = btn.querySelector('svg');

            if (formCard.classList.contains('hidden')) {
                // Mostra il form
                formCard.classList.remove('hidden');
                btnText.textContent = 'Chiudi';
                // Cambia icona in X
                icon.innerHTML = '<line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line>';
            } else {
                // Nascondi il form
                formCard.classList.add('hidden');
                btnText.textContent = 'Aggiungi';
                // Ripristina icona +
                icon.innerHTML = '<line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line>';
            }
        }
    </script>
</x-layout>
