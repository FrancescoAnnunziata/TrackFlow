<x-filament-panels::page>
    <div class="mx-auto w-full max-w-xl">
        <form wire:submit="search">
            <div class="flex flex-col gap-3">
                <label for="scan-code" class="text-sm font-medium">
                    Spara il barcode con il lettore USB (oppure digita il codice) e premi Invio
                </label>

                <input
                    id="scan-code"
                    type="text"
                    wire:model="code"
                    autofocus
                    autocomplete="off"
                    placeholder="Es. G8-FED-0001"
                    class="fi-input block w-full rounded-lg border-gray-300 shadow-sm text-lg font-mono dark:border-white/10 dark:bg-white/5"
                />

                <x-filament::button type="submit">
                    Cerca dispositivo
                </x-filament::button>
            </div>
        </form>

        @if ($notFound)
            <div class="mt-6 rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-900 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200">
                <p class="font-medium">Nessun dispositivo trovato per il codice <span class="font-mono">{{ $code }}</span>.</p>
                <div class="mt-3">
                    <x-filament::button wire:click="createWithCode" color="warning">
                        Crea nuovo dispositivo con questo codice
                    </x-filament::button>
                </div>
            </div>
        @endif
    </div>

    <script>
        // Riporta sempre il focus sull'input dopo una ricerca, così il lettore
        // USB può sparare il barcode successivo senza click manuali.
        document.addEventListener('livewire:navigated', () => {
            document.getElementById('scan-code')?.focus();
        });
        Livewire.hook('morphed', () => {
            document.getElementById('scan-code')?.focus();
        });
    </script>
</x-filament-panels::page>
