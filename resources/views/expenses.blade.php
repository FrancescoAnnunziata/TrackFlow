<x-layout title="Spese">

        {{-- Intestazione pagina --}}
        <div class="mb-8">
            <h1 class="text-2xl font-bold text-foreground">Registra spesa</h1>
            <p class="mt-1 text-sm text-muted-foreground">Inserisci i dettagli di una nuova spesa.</p>
        </div>

        {{-- Card form --}}
        <div class="bg-layer border border-layer-line rounded-xl shadow-sm p-6 sm:p-8">
            <form method="POST" action="#" enctype="multipart/form-data" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">

                    {{-- Data --}}
                    <div>
                        <label for="expense-date" class="block text-sm font-medium text-foreground mb-1.5">
                            Data
                        </label>
                        <input
                            type="date"
                            id="expense-date"
                            name="date"
                            value="{{ old('date', date('Y-m-d')) }}"
                            required
                            class="py-2.5 px-4 block w-full rounded-lg border border-layer-line bg-layer text-foreground text-sm placeholder:text-muted-foreground focus:border-primary focus:ring-2 focus:ring-primary/30 disabled:opacity-50 disabled:pointer-events-none"
                        >
                        @error('date')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Valore della spesa --}}
                    <div>
                        <label for="expense-amount" class="block text-sm font-medium text-foreground mb-1.5">
                            Valore (€)
                        </label>
                        <div class="relative">
                            <input
                                type="number"
                                id="expense-amount"
                                name="amount"
                                value="{{ old('amount') }}"
                                min="0"
                                step="0.01"
                                placeholder="0.00"
                                required
                                class="py-2.5 ps-9 pe-4 block w-full rounded-lg border border-layer-line bg-layer text-foreground text-sm placeholder:text-muted-foreground focus:border-primary focus:ring-2 focus:ring-primary/30 disabled:opacity-50 disabled:pointer-events-none"
                            >
                            <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none ps-3">
                                <span class="text-muted-foreground text-sm">€</span>
                            </div>
                        </div>
                        @error('amount')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Tipologia di spesa --}}
                    <div>
                        <label for="expense-type" class="block text-sm font-medium text-foreground mb-1.5">
                            Tipologia di spesa
                        </label>
                        <select
                            id="expense-type"
                            name="type"
                            required
                            class="py-2.5 px-4 block w-full rounded-lg border border-layer-line bg-layer text-foreground text-sm focus:border-primary focus:ring-2 focus:ring-primary/30 disabled:opacity-50 disabled:pointer-events-none"
                        >
                            <option value="" disabled {{ old('type') ? '' : 'selected' }}>— Seleziona tipologia —</option>
                            <option value="travel"         {{ old('type') == 'travel'         ? 'selected' : '' }}>Viaggio</option>
                            <option value="accommodation"  {{ old('type') == 'accommodation'  ? 'selected' : '' }}>Alloggio</option>
                            <option value="food"           {{ old('type') == 'food'           ? 'selected' : '' }}>Pasti e ristorazione</option>
                            <option value="software"       {{ old('type') == 'software'       ? 'selected' : '' }}>Software e abbonamenti</option>
                            <option value="hardware"       {{ old('type') == 'hardware'       ? 'selected' : '' }}>Hardware e attrezzatura</option>
                            <option value="office"         {{ old('type') == 'office'         ? 'selected' : '' }}>Cancelleria e ufficio</option>
                            <option value="marketing"      {{ old('type') == 'marketing'      ? 'selected' : '' }}>Marketing e pubblicità</option>
                            <option value="other"          {{ old('type') == 'other'          ? 'selected' : '' }}>Altro</option>
                        </select>
                        @error('type')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Cliente --}}
                    <div>
                        <label for="expense-client" class="block text-sm font-medium text-foreground mb-1.5">
                            Cliente
                        </label>
                        <select
                            id="expense-client"
                            name="client_id"
                            class="py-2.5 px-4 block w-full rounded-lg border border-layer-line bg-layer text-foreground text-sm focus:border-primary focus:ring-2 focus:ring-primary/30 disabled:opacity-50 disabled:pointer-events-none"
                        >
                            <option value="">— Nessun cliente associato —</option>
                            {{-- @foreach($clients as $client) --}}
                            {{-- <option value="{{ $client->id }}" {{ old('client_id') == $client->id ? 'selected' : '' }}>{{ $client->name }}</option> --}}
                            {{-- @endforeach --}}
                        </select>
                        @error('client_id')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                </div>

                {{-- Note --}}
                <div>
                    <label for="expense-notes" class="block text-sm font-medium text-foreground mb-1.5">
                        Note
                        <span class="text-xs font-normal text-muted-foreground ms-1">(opzionale)</span>
                    </label>
                    <textarea
                        id="expense-notes"
                        name="notes"
                        rows="3"
                        placeholder="Aggiungi eventuali note sulla spesa…"
                        class="py-2.5 px-4 block w-full rounded-lg border border-layer-line bg-layer text-foreground text-sm placeholder:text-muted-foreground focus:border-primary focus:ring-2 focus:ring-primary/30 disabled:opacity-50 disabled:pointer-events-none resize-none"
                    >{{ old('notes') }}</textarea>
                    @error('notes')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Allegato (scontrino) --}}
                <div>
                    <label for="expense-attachment" class="block text-sm font-medium text-foreground mb-1.5">
                        Allegato scontrino
                        <span class="text-xs font-normal text-muted-foreground ms-1">(immagine o PDF)</span>
                    </label>

                    <label
                        for="expense-attachment"
                        class="group flex flex-col items-center justify-center w-full border-2 border-dashed border-layer-line rounded-lg p-6 cursor-pointer hover:border-primary hover:bg-layer-hover transition-colors duration-200"
                    >
                        <div class="flex flex-col items-center gap-2 text-center">
                            <svg class="size-9 text-muted-foreground group-hover:text-primary transition-colors" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                            </svg>
                            <div>
                                <p class="text-sm font-medium text-foreground group-hover:text-primary">
                                    Clicca per caricare
                                    <span class="font-normal text-muted-foreground">o trascina qui il file</span>
                                </p>
                                <p class="text-xs text-muted-foreground mt-0.5">PNG, JPG, JPEG, WEBP, PDF — max 10 MB</p>
                            </div>
                        </div>
                        <input
                            id="expense-attachment"
                            name="attachment"
                            type="file"
                            accept=".png,.jpg,.jpeg,.webp,.pdf"
                            class="sr-only"
                            onchange="updateFileName(this)"
                        >
                    </label>

                    <p id="file-name" class="mt-1.5 text-xs text-muted-foreground hidden">
                        <span class="font-medium text-foreground">File selezionato:</span>
                        <span id="file-name-text"></span>
                    </p>

                    @error('attachment')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Divider --}}
                <div class="border-t border-layer-line"></div>

                {{-- Azioni --}}
                <div class="flex items-center justify-end gap-3 pt-1">
                    <a
                        href="/expenses"
                        class="py-2.5 px-5 inline-flex items-center gap-x-2 text-sm font-medium rounded-lg border border-layer-line bg-layer text-foreground hover:bg-layer-hover focus:outline-none focus:bg-layer-hover transition-colors"
                    >
                        Annulla
                    </a>
                    <button
                        type="submit"
                        class="py-2.5 px-5 inline-flex items-center gap-x-2 text-sm font-semibold rounded-lg border border-transparent bg-primary text-primary-foreground hover:bg-primary-hover focus:outline-none focus:bg-primary-hover disabled:opacity-50 disabled:pointer-events-none transition-colors"
                    >
                        <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
                        </svg>
                        Salva spesa
                    </button>
                </div>

            </form>
        </div>

    <script>
        function updateFileName(input) {
            const label = document.getElementById('file-name');
            const nameText = document.getElementById('file-name-text');
            if (input.files && input.files.length > 0) {
                nameText.textContent = input.files[0].name;
                label.classList.remove('hidden');
            } else {
                label.classList.add('hidden');
            }
        }
    </script>

</x-layout>

