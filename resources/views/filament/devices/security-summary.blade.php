{{-- Stato attuale dei campi critici del censimento e da quanto dura. --}}
@php
    $stateClasses = [
        'risk' => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400 dark:ring-danger-400/30',
        'ok' => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400/30',
        'unknown' => 'bg-gray-50 text-gray-600 ring-gray-500/20 dark:bg-white/5 dark:text-gray-400 dark:ring-white/20',
    ];
    $stateLabels = ['risk' => 'A rischio', 'ok' => 'A posto', 'unknown' => 'Non rilevato'];
@endphp

@if ($rilevazioni === 0)
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Nessuna rilevazione da censimento per questo dispositivo.
    </p>
@else
    <ul class="divide-y divide-gray-100 dark:divide-white/10">
        @foreach ($summary as $key => $row)
            <li class="flex items-start justify-between gap-4 py-3">
                <div class="min-w-0">
                    <span class="inline-flex items-center gap-1">
                        <span class="font-medium text-gray-950 dark:text-white">{{ $row['label'] }}</span>

                        @if ($explanation = \App\Filament\Resources\DeviceSecurityChecks\Schemas\DeviceSecurityCheckInfolist::criticalExplanation($key))
                            <svg
                                title="{{ $explanation }}"
                                xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                                class="h-4 w-4 shrink-0 cursor-help text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                            </svg>
                        @endif
                    </span>

                    @if ($row['detail'])
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $row['detail'] }}</p>
                    @endif

                    @if ($row['streak'] > 1)
                        <p class="mt-0.5 text-xs text-danger-600 dark:text-danger-400">
                            In questo stato da {{ $row['streak'] }} rilevazioni consecutive{{ $row['since'] ? ', dal '.$row['since']->format('d/m/Y') : '' }}
                            @if ($row['days'] !== null)
                                ({{ $row['days'] }} giorni)
                            @endif
                        </p>
                    @endif
                </div>

                <span @class([
                    'shrink-0 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset',
                    $stateClasses[$row['state']] ?? $stateClasses['unknown'],
                ])>
                    {{ $stateLabels[$row['state']] ?? $row['state'] }}
                </span>
            </li>
        @endforeach
    </ul>

    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
        {{ $rilevazioni }} {{ $rilevazioni === 1 ? 'rilevazione' : 'rilevazioni' }} in archivio.
    </p>
@endif
