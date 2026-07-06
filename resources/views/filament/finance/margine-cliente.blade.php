<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Margine per cliente</x-slot>

        @php($rows = $this->getRows())

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-2 pr-4">Cliente</th>
                        <th class="py-2 pr-4 text-right">Fatturato</th>
                        <th class="py-2 pr-4 text-right">Spese</th>
                        <th class="py-2 pr-4 text-right">Ore</th>
                        <th class="py-2 text-right">Margine</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-t border-gray-100 dark:border-white/10">
                            <td class="py-2 pr-4 font-medium">{{ $row['name'] }}</td>
                            <td class="py-2 pr-4 text-right">€ {{ number_format($row['fatturato'], 2, ',', '.') }}</td>
                            <td class="py-2 pr-4 text-right">€ {{ number_format($row['spese'], 2, ',', '.') }}</td>
                            <td class="py-2 pr-4 text-right">{{ number_format($row['ore'], 2, ',', '.') }}</td>
                            <td class="py-2 text-right font-semibold {{ $row['margine'] >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                € {{ number_format($row['margine'], 2, ',', '.') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-4 text-center text-gray-500 dark:text-gray-400">
                                Nessun dato nel periodo selezionato.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
