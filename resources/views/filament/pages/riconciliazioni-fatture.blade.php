<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:gap-4">
            <div>
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300" for="from">Dal</label>
                <input type="date" id="from" wire:model.live="from"
                    class="fi-input block w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 dark:text-white" />
            </div>
            <div>
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300" for="until">Al</label>
                <input type="date" id="until" wire:model.live="until"
                    class="fi-input block w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 dark:text-white" />
            </div>
        </div>
    </x-filament::section>

    @php
        $table = $this->tableData();
        $numeric = $this->numericColumns();
    @endphp

    <x-filament::section>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10 text-left">
                        @foreach ($table['headings'] as $i => $heading)
                            <th class="px-3 py-2 font-semibold whitespace-nowrap {{ in_array($i, $numeric) ? 'text-right' : '' }}">
                                {{ $heading }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($table['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5
                            {{ $row['kind'] === 'total' ? 'font-bold border-t-2 border-gray-300 dark:border-white/20' : '' }}">
                            @foreach ($row['cells'] as $i => $cell)
                                <td class="px-3 py-2 align-top {{ in_array($i, $numeric) ? 'text-right whitespace-nowrap tabular-nums' : '' }}">
                                    @if (in_array($i, $numeric) && is_numeric($cell) && $cell !== '')
                                        € {{ number_format((float) $cell, 2, ',', '.') }}
                                    @elseif (\Illuminate\Support\Str::startsWith($cell, 'http'))
                                        <a href="{{ $cell }}" target="_blank" rel="noopener"
                                            class="text-primary-600 dark:text-primary-400 underline">apri PDF</a>
                                    @else
                                        <span style="white-space: pre-line;">{{ $cell }}</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($table['headings']) }}" class="px-3 py-6 text-center text-gray-500">
                                {{ $this->emptyMessage }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
