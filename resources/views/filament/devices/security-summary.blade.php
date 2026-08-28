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
                    <span class="font-medium text-gray-950 dark:text-white">{{ $row['label'] }}</span>

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
