<x-filament-panels::page>
    @if ($extracting)
        {{-- Interroga il job di estrazione ogni pochi secondi finché non è pronto. --}}
        <div
            wire:poll.4s="checkExtraction"
            class="fi-section rounded-xl bg-primary-50 p-4 text-sm text-primary-700 ring-1 ring-primary-600/10 dark:bg-primary-400/10 dark:text-primary-300"
        >
            <div class="flex items-center gap-3">
                <x-filament::loading-indicator class="h-5 w-5" />
                <span>Estrazione dei PDF in corso in background… i dati compariranno qui appena pronti.</span>
            </div>
        </div>
    @endif

    {{ $this->form }}
</x-filament-panels::page>
