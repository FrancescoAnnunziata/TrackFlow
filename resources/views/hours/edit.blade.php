<x-layout title="Modifica ore">
    <div class="max-w-2xl mx-auto px-4 py-10">

        <div class="mb-8 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-foreground">Modifica ore</h1>
                <p class="mt-1 text-sm text-muted-foreground">Aggiorna i dati della registrazione selezionata.</p>
            </div>
            <a
                href="/hours/{{ $hour->id }}"
                class="py-2.5 px-5 inline-flex items-center gap-x-2 text-sm font-medium rounded-lg border border-layer-line bg-layer text-foreground hover:bg-layer-hover focus:outline-none focus:bg-layer-hover transition-colors"
            >
                Torna al dettaglio
            </a>
        </div>

        <div class="bg-layer border border-layer-line rounded-xl shadow-sm p-6 sm:p-8">
            <form action="/hours/{{ $hour->id }}" method="POST" class="space-y-5">
                @csrf
                @method('PATCH')

                <div>
                    <label for="date" class="block text-sm font-medium text-foreground mb-1.5">
                        Data
                    </label>
                    <input
                        type="date"
                        id="date"
                        name="date"
                        value="{{ old('date', \Carbon\Carbon::parse($hour->date)->format('Y-m-d')) }}"
                        required
                        class="py-2.5 px-4 block w-full rounded-lg border border-layer-line bg-layer text-foreground text-sm placeholder:text-muted-foreground focus:border-primary focus:ring-2 focus:ring-primary/30 disabled:opacity-50 disabled:pointer-events-none"
                    />
                </div>

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
                        value="{{ old('hours', $hour->hours) }}"
                        required
                        class="py-2.5 px-4 block w-full rounded-lg border border-layer-line bg-layer text-foreground text-sm placeholder:text-muted-foreground focus:border-primary focus:ring-2 focus:ring-primary/30 disabled:opacity-50 disabled:pointer-events-none"
                    />
                </div>

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
                        <option value="">- Seleziona cliente -</option>
                        <option value="1" @selected(old('client_id', $hour->client_id) == 1)>Cliente Example 1</option>
                        <option value="2" @selected(old('client_id', $hour->client_id) == 2)>Cliente Example 2</option>
                    </select>
                </div>

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
                        placeholder="Descrivi brevemente l'attivita svolta..."
                    >{{ old('notes', $hour->notes) }}</textarea>
                </div>

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
                            @checked(old('billable', $hour->billable))
                            class="peer sr-only"
                        />
                        <span
                            class="absolute inset-0 rounded-full bg-layer-line transition-colors duration-200 peer-checked:bg-primary"></span>
                        <span
                            class="absolute top-0.5 left-0.5 size-5 rounded-full bg-white shadow transition-transform duration-200 peer-checked:translate-x-5"></span>
                    </label>
                </div>

                <div class="border-t border-layer-line"></div>

                <div class="flex items-center justify-end gap-3 pt-1">
                    <a
                        href="/hours/{{ $hour->id }}"
                        class="py-2.5 px-5 inline-flex items-center gap-x-2 text-sm font-medium rounded-lg border border-layer-line bg-layer text-foreground hover:bg-layer-hover focus:outline-none focus:bg-layer-hover transition-colors"
                    >
                        Annulla
                    </a>
                    <button
                        type="submit"
                        class="py-2.5 px-5 inline-flex items-center gap-x-2 text-sm font-semibold rounded-lg border border-transparent bg-primary text-primary-foreground hover:bg-primary-hover focus:outline-none focus:bg-primary-hover disabled:opacity-50 disabled:pointer-events-none transition-colors"
                    >
                        Aggiorna ore
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-layout>

