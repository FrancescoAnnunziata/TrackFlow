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
        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
            Sono le fatture dei fornitori con sede fuori dall'Italia, note di credito comprese (in negativo).
            Se una fattura manca o è di troppo, il motivo è quasi sempre il <strong>paese del fornitore</strong>:
            si corregge in <em>Fornitori</em>.
        </p>
    </x-filament::section>

    @php($table = $this->tableData())
    @php($numeric = \App\Services\Reporting\FattureEstereBuilder::NUMERIC_COLUMNS)
    @php($link = \App\Services\Reporting\FattureEstereBuilder::LINK_COLUMN)

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
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            @foreach ($row as $i => $cell)
                                <td class="px-3 py-2 align-top {{ in_array($i, $numeric) ? 'text-right whitespace-nowrap tabular-nums' : '' }}">
                                    @if (in_array($i, $numeric) && is_numeric($cell))
                                        {{ number_format((float) $cell, 2, ',', '.') }}
                                    @elseif ($i === $link && \Illuminate\Support\Str::startsWith($cell, 'http'))
                                        <a href="{{ $cell }}" target="_blank" rel="noopener"
                                            class="text-primary-600 dark:text-primary-400 underline">apri PDF</a>
                                    @elseif ($i === $link)
                                        <span class="text-danger-600">manca il PDF</span>
                                    @else
                                        {{ $cell }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($table['headings']) }}" class="px-3 py-6 text-center text-gray-500">
                                Nessuna fattura estera nel periodo selezionato.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($table['rows'] !== [])
                    <tfoot>
                        <tr class="font-bold border-t-2 border-gray-300 dark:border-white/20">
                            <td class="px-3 py-2" colspan="11">{{ count($table['rows']) }} documenti — totale in euro</td>
                            <td class="px-3 py-2 text-right tabular-nums whitespace-nowrap">{{ number_format($table['totale'], 2, ',', '.') }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-filament::section>

    @php($orfane = app(\App\Services\Reporting\FattureEstereBuilder::class)->unlinkedIntegrations())
    @if ($orfane !== [])
        <x-filament::section>
            <x-slot name="heading">Integrazioni senza fattura estera ({{ count($orfane) }})</x-slot>
            <x-slot name="description">
                Il commercialista ha emesso l'integrazione, ma in TrackFlow la fattura estera che integra non c'è
                (o non si riconosce). Caricala da «Fatture estere» con lo stesso numero: l'integrazione si collega da sola.
            </x-slot>
            <ul class="text-sm list-disc pl-5 space-y-1">
                @foreach ($orfane as $i)
                    <li>
                        {{ $i->number }} del {{ $i->document_date?->format('d/m/Y') }} —
                        {{ $i->supplier?->name }} —
                        integra la fattura <strong>{{ $i->integrates_number ?? 'non indicata' }}</strong>
                        @if ($i->integrates_date) del {{ $i->integrates_date->format('d/m/Y') }} @endif
                        (imponibile € {{ number_format((float) $i->amount_net, 2, ',', '.') }})
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
