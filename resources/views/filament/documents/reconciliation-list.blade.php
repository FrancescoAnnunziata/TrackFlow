<div class="space-y-3">
    <ul class="divide-y divide-gray-100 dark:divide-white/10">
        @foreach ($rows as $row)
            <li class="flex items-center justify-between gap-4 py-3">
                <div class="min-w-0">
                    @if (($row['kind'] ?? null))
                        <span class="mr-1 inline-block rounded px-1.5 py-0.5 text-[0.65rem] font-semibold uppercase tracking-wide {{ $row['kind'] === 'Nota di credito' ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400' : 'bg-primary-100 text-primary-700 dark:bg-primary-500/15 dark:text-primary-400' }}">
                            {{ $row['kind'] }}
                        </span>
                    @endif

                    @if ($row['url'])
                        <a
                            href="{{ $row['url'] }}"
                            class="font-medium text-primary-600 hover:underline dark:text-primary-400"
                        >
                            {{ $row['label'] }}
                        </a>
                    @else
                        <span class="font-medium text-gray-950 dark:text-white">{{ $row['label'] }}</span>
                    @endif

                    @if ($row['matchedBy'])
                        <span class="ml-1 text-xs text-gray-500 dark:text-gray-400">
                            ({{ $row['matchedBy'] === 'auto' ? 'automatica' : 'manuale' }})
                        </span>
                    @endif
                </div>

                <span class="shrink-0 font-mono text-sm tabular-nums text-gray-950 dark:text-white">
                    € {{ number_format($row['amount'], 2, ',', '.') }}
                </span>
            </li>
        @endforeach
    </ul>

    @if ($remaining > 0.005)
        <p class="text-sm text-warning-600 dark:text-warning-400">
            Quota del documento ancora da riconciliare: € {{ number_format($remaining, 2, ',', '.') }}
        </p>
    @endif
</div>
