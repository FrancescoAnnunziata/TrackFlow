<div class="space-y-3">
    <ul class="divide-y divide-gray-100 dark:divide-white/10">
        @foreach ($rows as $row)
            <li class="flex items-center justify-between gap-4 py-3">
                <div class="min-w-0">
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

                <div class="flex shrink-0 items-center gap-3">
                    @if ($row['pdfUrl'])
                        <a
                            href="{{ $row['pdfUrl'] }}"
                            download="{{ $row['pdfName'] }}"
                            target="_blank"
                            class="inline-flex items-center gap-1 rounded-md bg-primary-600 px-2 py-1 text-xs font-medium text-white hover:bg-primary-500"
                        >
                            <x-heroicon-o-arrow-down-tray class="h-4 w-4" />
                            {{ $row['isPdf'] ? 'PDF' : 'Allegato' }}
                        </a>
                    @endif

                    <span class="font-mono text-sm tabular-nums text-gray-950 dark:text-white">
                        € {{ number_format($row['amount'], 2, ',', '.') }}
                    </span>
                </div>
            </li>
        @endforeach
    </ul>

    @if ($unreconciled > 0.005)
        <p class="text-sm text-warning-600 dark:text-warning-400">
            Quota non ancora riconciliata: € {{ number_format($unreconciled, 2, ',', '.') }}
        </p>
    @endif
</div>
