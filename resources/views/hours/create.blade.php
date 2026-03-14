<x-layout title="Aggiungi ore">
    <div class="max-w-2xl mx-auto px-4 py-10">
        {{-- Card form --}}
        <div id="hourFormCard" class="bg-layer border border-layer-line rounded-xl shadow-sm p-6 sm:p-8">
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
                        value="{{ old('date', date('Y-m-d')) }}"
                        class="py-2.5 px-4 block w-full rounded-lg border {{ $errors->has('date') ? 'border-red-400 focus:border-red-400 focus:ring-red-400/30' : 'border-layer-line focus:border-primary focus:ring-primary/30' }} bg-layer text-foreground text-sm placeholder:text-muted-foreground focus:ring-2 disabled:opacity-50 disabled:pointer-events-none"
                    />
                    @error('date')
                        <p class="mt-1.5 text-xs text-red-500">{{ $message }}</p>
                    @enderror
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
                        min="0.5"
                        max="24"
                        step="0.5"
                        value="{{ old('hours') }}"
                        class="py-2.5 px-4 block w-full rounded-lg border {{ $errors->has('hours') ? 'border-red-400 focus:border-red-400 focus:ring-red-400/30' : 'border-layer-line focus:border-primary focus:ring-primary/30' }} bg-layer text-foreground text-sm placeholder:text-muted-foreground focus:ring-2 disabled:opacity-50 disabled:pointer-events-none"
                        placeholder="Inserisci il numero di ore da registrare"
                    />
                    @error('hours')
                        <p class="mt-1.5 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Cliente --}}
                <div>
                    <label for="client_id" class="block text-sm font-medium text-foreground mb-1.5">
                        Cliente
                    </label>
                    <select
                        id="client_id"
                        name="client_id"
                        class="py-2.5 px-4 block w-full rounded-lg border {{ $errors->has('client_id') ? 'border-red-400 focus:border-red-400 focus:ring-red-400/30' : 'border-layer-line focus:border-primary focus:ring-primary/30' }} bg-layer text-foreground text-sm focus:ring-2 disabled:opacity-50 disabled:pointer-events-none"
                    >
                        <option value="">— Seleziona cliente —</option>
                        <option value="1" @selected(old('client_id') == 1)>Cliente Example 1</option>
                        <option value="2" @selected(old('client_id') == 2)>Cliente Example 2</option>
                    </select>
                    @error('client_id')
                        <p class="mt-1.5 text-xs text-red-500">{{ $message }}</p>
                    @enderror
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
                        maxlength="1000"
                        class="py-2.5 px-4 block w-full rounded-lg border {{ $errors->has('notes') ? 'border-red-400 focus:border-red-400 focus:ring-red-400/30' : 'border-layer-line focus:border-primary focus:ring-primary/30' }} bg-layer text-foreground text-sm placeholder:text-muted-foreground focus:ring-2 disabled:opacity-50 disabled:pointer-events-none"
                        placeholder="Descrivi brevemente l'attività svolta..."
                    >{{ old('notes') }}</textarea>
                    @error('notes')
                        <p class="mt-1.5 text-xs text-red-500">{{ $message }}</p>
                    @enderror
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
                        href="/hours"
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
    </div>
</x-layout>
