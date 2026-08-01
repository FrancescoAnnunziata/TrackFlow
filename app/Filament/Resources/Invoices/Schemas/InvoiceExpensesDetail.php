<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Models\Expense;
use App\Models\Invoice;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Storage;

/**
 * Dettaglio delle spese agganciate alla riga art. 15 "Rimborsi spese".
 *
 * In fattura la riga è unica ("Vedi note") e il dettaglio viene composto solo
 * all'invio, da Invoice::buildNotesHtml(). Questa sezione mostra lo stesso
 * dettaglio già in bozza — con i giustificativi — così il rimborso si può
 * controllare prima di spedire. Sta sia nella form (dove atterra "Genera
 * fattura") sia nella pagina di sola lettura.
 */
class InvoiceExpensesDetail
{
    public static function section(): Section
    {
        return Section::make('Dettaglio rimborsi spese')
            ->description('Le spese agganciate alla riga art. 15. È lo stesso dettaglio che finirà nelle note della fattura su Fatture in Cloud.')
            ->visible(fn (?Invoice $record): bool => $record?->expenses()->exists() ?? false)
            ->components([
                RepeatableEntry::make('expenses')
                    ->hiddenLabel()
                    ->state(fn (?Invoice $record): array => $record?->expenses->sortBy('date')->values()->all() ?? [])
                    // Layout a tabella: intestazioni una volta sola, una riga per spesa.
                    ->table([
                        TableColumn::make('Data'),
                        TableColumn::make('Importo'),
                        TableColumn::make('Conto'),
                        TableColumn::make('Note'),
                        TableColumn::make('Giustificativo'),
                    ])
                    ->components([
                        TextEntry::make('date')
                            ->date('d/m/Y'),
                        TextEntry::make('amount')
                            ->money('EUR'),
                        TextEntry::make('conto')
                            ->state(fn (Expense $record): string => (string) ($record->conto ?: '—')),
                        TextEntry::make('notes')
                            ->state(fn (Expense $record): string => (string) ($record->notes ?: '—')),
                        TextEntry::make('giustificativo')
                            ->state(fn (Expense $record): string => self::attachmentsHtml($record))
                            ->html(),
                    ]),
                TextEntry::make('expenses_total')
                    ->label('Totale rimborsi')
                    ->state(fn (?Invoice $record): float => round((float) ($record?->expenses->sum('amount') ?? 0), 2))
                    ->money('EUR')
                    ->weight('bold'),
                TextEntry::make('expenses_mismatch')
                    ->hiddenLabel()
                    ->state(fn (?Invoice $record): string => $record === null ? '' : sprintf(
                        'La riga art. 15 in fattura vale € %s: %s della somma delle spese qui sopra (scarto € %s). Correggi la riga o le spese agganciate.',
                        number_format($record->art15Total(), 2, ',', '.'),
                        self::mismatch($record) > 0 ? 'più' : 'meno',
                        number_format(abs(self::mismatch($record)), 2, ',', '.'),
                    ))
                    ->color('warning')
                    ->visible(fn (?Invoice $record): bool => $record !== null && abs(self::mismatch($record)) > 0.005)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Scarto fra il totale della riga art. 15 e la somma delle spese agganciate:
     * diverso da zero se la riga è stata ritoccata a mano.
     */
    public static function mismatch(Invoice $invoice): float
    {
        return round($invoice->art15Total() - (float) $invoice->expenses->sum('amount'), 2);
    }

    /**
     * Link ai giustificativi: le foto anche come miniatura, così il controllo si
     * fa a colpo d'occhio senza aprire la spesa.
     */
    private static function attachmentsHtml(Expense $expense): string
    {
        $paths = array_values($expense->attachaments ?? []);

        if ($paths === []) {
            return '<span style="opacity:0.7;">manca</span>';
        }

        $links = collect($paths)->map(function (string $path, int $i) use ($paths): string {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $label = ($ext === 'pdf' ? 'PDF' : 'foto').(count($paths) > 1 ? ' '.($i + 1) : '');
            $url = Storage::disk('public')->url($path);

            // Miniatura solo per i formati che il browser sa disegnare: gli HEIC
            // dell'iPhone restano un link, altrimenti si vedrebbe l'icona rotta.
            $thumb = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'], true) ? sprintf(
                '<img src="%s" alt="%s" style="height:2.5rem;width:2.5rem;object-fit:cover;border-radius:0.375rem;">',
                e($url),
                e($label),
            ) : '';

            return sprintf(
                '<a href="%s" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:0.375rem;text-decoration:underline;">%s%s</a>',
                e($url),
                $thumb,
                e($label),
            );
        })->implode(' ');

        return sprintf('<span style="display:inline-flex;flex-wrap:wrap;align-items:center;gap:0.5rem;">%s</span>', $links);
    }
}
