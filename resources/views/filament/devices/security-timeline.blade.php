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
            <h3 class="inline-flex items-center gap-1 text-sm font-semibold text-gray-950 dark:text-white">
                {{ $block['label'] }}

                @if ($explanation = \App\Filament\Resources\DeviceSecurityChecks\Schemas\DeviceSecurityCheckInfolist::criticalExplanation($key))
                    <svg
                        title="{{ $explanation }}"
                        xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                        class="h-4 w-4 shrink-0 cursor-help text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                    </svg>
                @endif
            </h3>

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
