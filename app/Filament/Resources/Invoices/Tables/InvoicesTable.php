<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Filament\Resources\Hours\HourResource;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Invoice;
use App\Services\Reconciliation\ReconciliationService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('Numero')
                    ->placeholder('da assegnare')
                    ->searchable()
                    ->sortable()
                    ->color('primary')
                    ->icon('heroicon-o-clock')
                    ->tooltip('Vedi le ore di questa fattura')
                    ->url(fn (Invoice $record): string => HourResource::getUrl('index', [
                        'tableFilters' => [
                            'invoice' => ['value' => $record->getKey()],
                        ],
                    ])),
                TextColumn::make('issue_date')
                    ->label('Data')
                    ->date()
                    ->sortable(),
                TextColumn::make('client.name')
                    ->label('Cliente')
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === Invoice::TYPE_CREDIT_NOTE ? 'Nota di credito' : 'Fattura')
                    ->color(fn (string $state): string => $state === Invoice::TYPE_CREDIT_NOTE ? 'danger' : 'gray')
                    ->description(fn (Invoice $record): ?string => $record->isCreditNote() && $record->relatedInvoice
                        ? 'storna '.$record->relatedInvoice->number
                        : null)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total')
                    ->label('Totale')
                    ->state(fn (Invoice $record): float => $record->total())
                    ->money('EUR')
                    ->description(fn (Invoice $record): ?string => (! $record->isCreditNote() && $record->creditedAmount() > 0)
                        ? 'da incassare € '.number_format($record->amountToCollect(), 2, ',', '.').' (stornata di € '.number_format($record->creditedAmount(), 2, ',', '.').')'
                        : null)
                    ->sortable(false),
                TextColumn::make('status')
                    ->label('Stato')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'draft' => 'Bozza',
                        'sent' => 'Inviata',
                        'paid' => 'Pagata',
                        default => $state,
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'gray',
                        'sent' => 'warning',
                        'paid' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                IconColumn::make('reconciled')
                    ->label('Incassata')
                    ->state(fn (Invoice $record): bool => $record->reconciliations()->exists())
                    ->boolean()
                    ->toggleable(),
                IconColumn::make('fic_sent_at')
                    ->label('FIC')
                    ->tooltip(fn (Invoice $record): ?string => $record->isSentToFic() ? 'Inviata a Fatture in Cloud' : null)
                    ->boolean()
                    ->trueIcon('heroicon-o-cloud')
                    ->falseIcon('heroicon-o-minus-small')
                    ->getStateUsing(fn (Invoice $record): bool => $record->isSentToFic())
                    ->color(fn (Invoice $record): string => $record->isSentToFic() ? 'success' : 'gray'),
                TextColumn::make('user.name')
                    ->label('Creata da')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('issue_date', 'desc')
            ->filters([
                SelectFilter::make('client')
                    ->label('Cliente')
                    ->relationship('client', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options([
                        Invoice::TYPE_INVOICE => 'Fattura',
                        Invoice::TYPE_CREDIT_NOTE => 'Nota di credito',
                    ]),
                SelectFilter::make('status')
                    ->label('Stato')
                    ->options([
                        'draft' => 'Bozza',
                        'sent' => 'Inviata',
                        'paid' => 'Pagata',
                    ]),
                Filter::make('issue_date')
                    ->label('Data emissione')
                    ->schema([
                        DatePicker::make('from')->label('Dal'),
                        DatePicker::make('until')->label('Al'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('issue_date', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('issue_date', '<=', $date));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) {
                            $indicators[] = Indicator::make('Dal '.Carbon::parse($data['from'])->toFormattedDateString())
                                ->removeField('from');
                        }
                        if ($data['until'] ?? null) {
                            $indicators[] = Indicator::make('Al '.Carbon::parse($data['until'])->toFormattedDateString())
                                ->removeField('until');
                        }

                        return $indicators;
                    }),
            ])
            ->recordActions([
                self::reconcileAction(),
                self::linkCreditNoteAction(),
                ViewAction::make(),
                EditAction::make(),
            ], position: RecordActionsPosition::BeforeColumns)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Segna la fattura attiva come incassata scegliendo il/i movimento/i bancario/i
     * in entrata che la coprono, da un elenco pre-filtrato (±45 giorni dalla data di
     * emissione, importo ±50%). Multiselect per gli incassi spezzati (una fattura
     * coperta da più accrediti). Alla copertura lo stato passa a "Pagata".
     */
    private static function reconcileAction(): Action
    {
        return Action::make('registra_incasso')
            // Label su due righe per non allargare la colonna azioni.
            ->label(new HtmlString('Registra<br>incasso'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            // Niente riconciliazione incassi per i clienti Fiscozen (partita IVA
            // forfettaria): quelle fatture non passano dai nostri conti, non ci sono
            // movimenti bancari da agganciare.
            ->visible(fn (Invoice $record): bool => $record->status === 'sent'
                && ! $record->isCreditNote()
                && $record->amountToCollect() > 0.01
                && $record->client?->invoicing_provider !== Client::PROVIDER_FISCOZEN)
            ->modalHeading('Registra incasso')
            ->modalDescription(fn (Invoice $record): string => sprintf(
                'Fattura %s — %s — da incassare € %s — %s',
                $record->number ?: '(s.n.)',
                $record->client->name ?? '—',
                number_format($record->amountToCollect(), 2, ',', '.'),
                optional($record->issue_date)->format('d/m/Y') ?? '',
            ))
            ->modalSubmitActionLabel('Registra incasso')
            ->schema([
                CheckboxList::make('transactions')
                    ->label('Seleziona il/i movimento/i in entrata che incassano questa fattura')
                    ->helperText('Entrate entro ±45 giorni dalla data di emissione e importo entro ±50% dell\'importo da incassare. Selezionane più di uno per un incasso a rate.')
                    ->options(fn (Invoice $record): array => self::candidateOptions($record))
                    ->descriptions(fn (Invoice $record): array => self::candidateDescriptions($record))
                    ->searchable()
                    ->bulkToggleable()
                    ->noSearchResultsMessage('Nessun movimento compatibile trovato.')
                    ->required(),
            ])
            ->action(function (array $data, Invoice $record): void {
                $service = app(ReconciliationService::class);
                $remaining = round($record->amountToCollect() - $record->reconciledAmount(), 2);
                $allocated = 0.0;

                foreach ($data['transactions'] ?? [] as $txId) {
                    if ($remaining <= 0.01) {
                        break;
                    }

                    $tx = BankTransaction::find($txId);
                    if ($tx === null) {
                        continue;
                    }

                    // Alloca al massimo la quota residua della fattura (gestisce sia
                    // l'incasso spezzato sia l'accredito cumulativo, di cui prende solo la parte).
                    $alloc = round(min($tx->unreconciledAmount(), $remaining), 2);
                    if ($alloc <= 0.01) {
                        continue;
                    }

                    $service->attach($tx, $record, $alloc);
                    $remaining = round($remaining - $alloc, 2);
                    $allocated += $alloc;
                }

                if ($allocated <= 0.01) {
                    Notification::make()->warning()->title('Nessun importo allocato')->send();

                    return;
                }

                Notification::make()->success()
                    ->title($record->fresh()->status === 'paid' ? 'Fattura segnata incassata' : 'Incasso parziale registrato')
                    ->send();
            });
    }

    /**
     * Collega manualmente una nota di credito attiva alla fattura che storna.
     * Ridefinisce l'importo da incassare della fattura (e ne ricalcola lo stato),
     * sia della nuova fattura collegata sia dell'eventuale precedente.
     */
    private static function linkCreditNoteAction(): Action
    {
        return Action::make('collega_fattura')
            ->label('Collega a fattura')
            ->icon('heroicon-o-link')
            ->color('warning')
            ->visible(fn (Invoice $record): bool => $record->isCreditNote())
            ->modalHeading('Collega la nota di credito alla fattura stornata')
            ->modalDescription(fn (Invoice $record): string => sprintf(
                'NC %s — %s — € %s',
                $record->number ?: '(s.n.)',
                $record->client->name ?? '—',
                number_format($record->total(), 2, ',', '.'),
            ))
            ->fillForm(fn (Invoice $record): array => ['related_invoice_id' => $record->related_invoice_id])
            ->schema([
                Select::make('related_invoice_id')
                    ->label('Fattura stornata da questa nota di credito')
                    ->options(fn (Invoice $record): array => self::relatedInvoiceOptions($record))
                    ->searchable()
                    ->helperText('Riduce l\'importo da incassare della fattura scelta. Lascia vuoto per scollegare.'),
            ])
            ->modalSubmitActionLabel('Salva collegamento')
            ->action(function (array $data, Invoice $record): void {
                $service = app(ReconciliationService::class);
                $previous = $record->relatedInvoice;

                $record->update(['related_invoice_id' => $data['related_invoice_id'] ?: null]);

                // Ricalcola lo stato sia della fattura ora collegata sia di quella
                // eventualmente scollegata (il loro importo da incassare è cambiato).
                foreach ([$previous, $record->fresh()->relatedInvoice] as $inv) {
                    if ($inv !== null) {
                        $service->recomputeDocument($inv->fresh());
                    }
                }

                Notification::make()->success()->title('Collegamento aggiornato')->send();
            });
    }

    /**
     * Fatture (non note di credito) dello stesso cliente, candidate allo storno.
     *
     * @return array<int, string>
     */
    private static function relatedInvoiceOptions(Invoice $record): array
    {
        return Invoice::where('type', Invoice::TYPE_INVOICE)
            ->where('client_id', $record->client_id)
            ->orderByDesc('issue_date')
            ->get()
            ->mapWithKeys(fn (Invoice $i): array => [
                $i->id => sprintf(
                    '%s — € %s — %s',
                    $i->number,
                    number_format($i->total(), 2, ',', '.'),
                    optional($i->issue_date)->format('d/m/Y') ?? '',
                ),
            ])
            ->all();
    }

    /**
     * Movimenti candidati per una fattura attiva: entrate con quota ancora da
     * allocare, entro ±45 giorni dalla data di emissione e importo entro ±50%
     * dell'importo da incassare (al netto degli storni). Ordinati per vicinanza.
     *
     * @return Collection<int, BankTransaction>
     */
    private static function candidateTransactions(Invoice $record): Collection
    {
        $total = $record->amountToCollect();
        $from = $record->issue_date->copy()->subDays(45);
        $to = $record->issue_date->copy()->addDays(45);

        return BankTransaction::query()
            ->with('bankAccount')
            ->where('amount', '>', 0)
            ->whereBetween('booked_at', [$from, $to])
            ->whereRaw('ABS(amount) BETWEEN ? AND ?', [$total * 0.5, $total * 1.5])
            ->orderBy('booked_at')
            ->get()
            ->filter(fn (BankTransaction $tx): bool => $tx->unreconciledAmount() > 0.01)
            ->sortBy(fn (BankTransaction $tx): float => abs((float) $tx->amount - $total))
            ->values();
    }

    /**
     * Etichetta di ogni movimento nella lista di scelta: data e importo residuo.
     *
     * @return array<int, string>
     */
    private static function candidateOptions(Invoice $record): array
    {
        return self::candidateTransactions($record)
            ->mapWithKeys(fn (BankTransaction $tx): array => [
                $tx->id => sprintf(
                    '%s  ·  € %s',
                    optional($tx->booked_at)->format('d/m/Y') ?? '',
                    number_format($tx->unreconciledAmount(), 2, ',', '.'),
                ),
            ])
            ->all();
    }

    /**
     * Descrizione (riga sotto l'etichetta): conto bancario + descrizione del movimento.
     *
     * @return array<int, string>
     */
    private static function candidateDescriptions(Invoice $record): array
    {
        return self::candidateTransactions($record)
            ->mapWithKeys(fn (BankTransaction $tx): array => [
                $tx->id => trim(sprintf(
                    '%s — %s',
                    $tx->bankAccount->name ?? '',
                    str($tx->description ?: ($tx->counterparty ?? ''))->limit(70)->value(),
                ), ' —'),
            ])
            ->all();
    }
}
