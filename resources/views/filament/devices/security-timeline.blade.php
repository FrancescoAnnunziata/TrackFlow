{{-- Cambi di stato dei campi critici nel tempo: solo i punti in cui il valore
     e' effettivamente cambiato, non tutte le rilevazioni. --}}
@php
    $stateLabels = ['risk' => 'a rischio', 'ok' => 'a posto', 'unknown' => 'non rilevato'];
    $stateColors = [
        'risk' => 'text-danger-600 dark:text-danger-400',
        'ok' => 'text-success-600 dark:text-success-400',
        'unknown' => 'text-gray-500 dark:text-gray-400',
    ];
@endphp

<div class="space-y-6">
    @forelse ($timeline as $key => $block)
        <div>
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $block['label'] }}</h3>

            @if ($block['transitions']->isEmpty())
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Nessuna rilevazione.</p>
            @else
                <ul class="mt-2 space-y-1">
                    @foreach ($block['transitions'] as $change)
                        <li class="text-sm text-gray-700 dark:text-gray-300">
                            <span class="font-mono tabular-nums">{{ $change['at']->format('d/m/Y') }}</span>
                            —
                            @if ($change['from'] === null)
                                prima rilevazione:
                            @else
                                da <span class="{{ $stateColors[$change['from']] ?? '' }}">{{ $stateLabels[$change['from']] ?? $change['from'] }}</span> a
                            @endif
                            <span class="font-medium {{ $stateColors[$change['to']] ?? '' }}">
                                {{ $stateLabels[$change['to']] ?? $change['to'] }}
                            </span>

                            @if ($change['detail'])
                                <span class="text-gray-500 dark:text-gray-400">({{ $change['detail'] }})</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">Nessuna rilevazione da censimento per questo dispositivo.</p>
    @endforelse
</div>
