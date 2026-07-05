<x-filament-panels::page>
    @php
        $credential = $this->getCredential();
        $configured = filled(config('services.fic.client_id'))
            && filled(config('services.fic.client_secret'))
            && filled(config('services.fic.redirect'));
    @endphp

    @unless ($configured)
        <x-filament::section>
            <x-slot name="heading">Configurazione incompleta</x-slot>

            <p class="text-sm text-gray-600 dark:text-gray-400">
                Imposta <code>FIC_CLIENT_ID</code>, <code>FIC_CLIENT_SECRET</code> e
                <code>FIC_REDIRECT_URI</code> nel file <code>.env</code>. Il redirect deve
                combaciare esattamente con quello registrato nell'app su Fatture in Cloud.
            </p>
        </x-filament::section>
    @endunless

    <x-filament::section>
        <x-slot name="heading">Stato connessione</x-slot>

        @if ($credential)
            <div class="flex flex-col gap-3 text-sm">
                <div class="flex items-center gap-2">
                    <x-filament::badge color="success">Connesso</x-filament::badge>
                </div>

                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">Azienda</span>
                        <div class="font-medium">{{ $credential->company_name ?: '—' }}</div>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">Company ID</span>
                        <div class="font-medium">{{ $credential->company_id ?: '—' }}</div>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">Token valido fino a</span>
                        <div class="font-medium">
                            {{ $credential->expires_at?->timezone('Europe/Rome')->format('d/m/Y H:i') ?? '—' }}
                            <span class="text-gray-400">(rinnovato in automatico)</span>
                        </div>
                    </div>
                </div>
            </div>
        @else
            <div class="flex flex-col gap-3 text-sm">
                <div>
                    <x-filament::badge color="gray">Non connesso</x-filament::badge>
                </div>
                <p class="text-gray-600 dark:text-gray-400">
                    TrackFlow non è ancora collegato a Fatture in Cloud. Usa il pulsante
                    <strong>Connetti</strong> in alto per autorizzare l'accesso.
                </p>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
