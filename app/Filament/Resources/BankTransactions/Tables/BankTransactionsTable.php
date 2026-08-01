<?php

namespace App\Filament\Resources\BankTransactions\Tables;

use App\Models\BankTransaction;
use App\Models\Costo;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Reimbursement;
use App\Models\Supplier;
use App\Services\Reconciliation\MatchSuggestionService;
use App\Services\Reconciliation\MovementActions;
use App\Services\Reconciliation\ReconciliationService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\HtmlString;

class BankTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('bankAccount.name')
                    ->label('Conto')
                    ->sortable(),
                TextColumn::make('booked_at')
                    ->label('Data')
                    ->date()
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Descrizione')
                    ->searchable()
                    ->wrap()
                    ->lineClamp(2)
                    ->extraCellAttributes(['style' => 'max-width: 22rem;']),
                TextColumn::make('amount')
                    ->label('Importo')
                    ->money('EUR')
                    ->color(fn ($record): string => $record->amount >= 0 ? 'success' : 'danger')
                    ->sortable(),
                IconColumn::make('reconciled')
                    ->label('Riconciliato')
                    ->boolean(),
                // Controparte in fondo: è lunga e in mezzo appesantiva la tabella.
                TextColumn::make('counterparty')
                    ->label('Controparte')
                    ->wrap()
                    ->searchable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('bank_account_id')
                    ->label('Conto')
                    ->relationship('bankAccount', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('direction')
                    ->label('Tipo')
                    ->options([
                        'in' => 'Entrate',
                        'out' => 'Uscite',
                    ]),
                TernaryFilter::make('reconciled')
                    ->label('Riconciliato')
                    // I giroconti sono già "chiusi" (spostamenti tra conti): non
                    // devono comparire tra i "non riconciliati".
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('reconciled', true),
                        false: fn (Builder $query): Builder => $query->where('reconciled', false)->whereNull('transfer_group_id'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Filter::make('booked_at')
                    ->label('Data')
                    ->schema([
                        DatePicker::make('from')->label('Dal'),
                        DatePicker::make('until')->label('Al'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('booked_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('booked_at', '<=', $date));
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
                Filter::make('amount')
                    ->label('Importo')
                    // Filtro sul valore assoluto: cercando "60" trova sia +60 (entrata)
                    // sia -60 (uscita). Per distinguere la direzione c'è il filtro "Tipo".
                    ->schema([
                        TextInput::make('min')->label('Importo min (€)')->numeric()->inputMode('decimal'),
                        TextInput::make('max')->label('Importo max (€)')->numeric()->inputMode('decimal'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(filled($data['min'] ?? null), fn (Builder $q) => $q->whereRaw('ABS(amount) >= ?', [$data['min']]))
                            ->when(filled($data['max'] ?? null), fn (Builder $q) => $q->whereRaw('ABS(amount) <= ?', [$data['max']]));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if (filled($data['min'] ?? null)) {
                            $indicators[] = Indicator::make('Importo ≥ € '.$data['min'])->removeField('min');
                        }
                        if (filled($data['max'] ?? null)) {
                            $indicators[] = Indicator::make('Importo ≤ € '.$data['max'])->removeField('max');
                        }

                        return $indicators;
                    }),
            ])
            ->defaultSort('booked_at', 'desc')
            ->recordActions([
                self::reconcileAction(),
                self::markAsCostoAction(),
                self::markAsTransferAction(),
                self::unreconcileAction(),
                self::unmarkTransferAction(),
                EditAction::make(),
                // Elimina il singolo movimento (con conferma): utile per doppioni o
                // righe di saldo entrate per sbaglio dall'import.
                DeleteAction::make()->requiresConfirmation(),
            ], position: RecordActionsPosition::BeforeColumns);
    }

    /**
     * Collega il movimento a un documento (fattura attiva/passiva o costo),
     * proponendo i candidati ordinati per confidenza e consentendo la scelta
     * manuale.
     */
    private static function reconcileAction(): Action
    {
        return Action::make('riconcilia')
            ->label('Riconcilia')
            ->icon(Heroicon::OutlinedLink)
            // Non su un giroconto (non è un costo/ricavo, solo spostamento).
            ->visible(fn (BankTransaction $record): bool => ! $record->isTransfer() && $record->unreconciledAmount() > 0.01)
            ->modalHeading('Riconcilia movimento')
            ->modalDescription(fn (BankTransaction $record): string => sprintf(
                '%s — € %s — %s — da riconciliare € %s',
                trim($record->description ?: ($record->counterparty ?: 'Movimento')),
                number_format(abs((float) $record->amount), 2, ',', '.'),
                optional($record->booked_at)->format('d/m/Y') ?? '',
                number_format($record->unreconciledAmount(), 2, ',', '.'),
            ))
            ->modalSubmitActionLabel('Riconcilia')
            ->schema([
                CheckboxList::make('documents')
                    ->label(fn (BankTransaction $record): string => $record->amount < 0
                        ? 'Fatture passive / costi da saldare con questo movimento'
                        : 'Fatture attive incassate da questo movimento')
                    ->helperText('Selezionane uno o più: puoi combinare più documenti la cui somma torna con l\'importo del movimento (es. due addebiti saldati insieme).')
                    ->options(fn (BankTransaction $record): array => self::poolOptions($record))
                    ->descriptions(fn (BankTransaction $record): array => self::poolDescriptions($record))
                    ->searchable()
                    ->bulkToggleable()
                    ->noSearchResultsMessage('Nessun documento compatibile trovato.')
                    ->live(),
                Placeholder::make('selected_total')
                    ->label('Totale selezionato')
                    ->content(function (Get $get, BankTransaction $record): string {
                        $amounts = self::poolAmounts($record);
                        $sum = collect($get('documents') ?? [])->sum(fn (string $k): float => (float) ($amounts[$k] ?? 0));
                        $target = $record->unreconciledAmount();
                        $ok = abs($sum - $target) <= 0.01;

                        return sprintf(
                            '€ %s di € %s%s',
                            number_format($sum, 2, ',', '.'),
                            number_format($target, 2, ',', '.'),
                            $ok ? ' — torna ✓' : '',
                        );
                    }),
                Section::make('Oppure scegli manualmente')
                    ->description('Per un documento fuori dall\'elenco (es. molto lontano nel tempo).')
                    ->collapsed()
                    ->columns(2)
                    ->components([
                        Select::make('manual_type')
                            ->label('Tipo documento')
                            ->options([
                                'invoice' => 'Fattura attiva',
                                'passive_invoice' => 'Fattura passiva',
                                'costo' => 'Costo',
                                'expense' => 'Spesa',
                                'reimbursement' => 'Rimborso spese',
                            ])
                            ->live(),
                        Select::make('manual_id')
                            ->label('Documento')
                            ->options(fn (Get $get): array => self::manualOptions($get('manual_type')))
                            ->searchable(),
                    ]),
            ])
            ->action(function (array $data, BankTransaction $record): void {
                $keys = collect($data['documents'] ?? []);
                if (filled($data['manual_type'] ?? null) && filled($data['manual_id'] ?? null)) {
                    $keys->push($data['manual_type'].':'.$data['manual_id']);
                }
                $keys = $keys->unique()->values();

                if ($keys->isEmpty()) {
                    Notification::make()->danger()->title('Nessun documento selezionato')->send();

                    return;
                }

                $service = app(ReconciliationService::class);
                $remaining = round($record->unreconciledAmount(), 2);
                $attached = 0;

                // Alloca a ciascun documento il suo residuo, finché la quota del
                // movimento non è esaurita: così una combinazione di documenti
                // (es. 2,60 + 2,93 = 5,53) chiude il movimento in un colpo solo.
                foreach ($keys as $key) {
                    if ($remaining <= 0.01) {
                        break;
                    }

                    [$alias, $id] = explode(':', $key, 2);
                    $class = Relation::getMorphedModel($alias) ?? $alias;
                    $document = $class::find($id);
                    if ($document === null) {
                        continue;
                    }

                    $alloc = round(min(self::documentResidual($document), $remaining), 2);
                    if ($alloc <= 0.01) {
                        continue;
                    }

                    $service->attach($record, $document, $alloc);
                    $remaining = round($remaining - $alloc, 2);
                    $attached++;
                }

                if ($attached === 0) {
                    Notification::make()->danger()->title('Nessuna quota da allocare sui documenti scelti')->send();

                    return;
                }

                Notification::make()->success()
                    ->title($attached > 1 ? "Movimento riconciliato a {$attached} documenti" : 'Movimento riconciliato')
                    ->body($remaining > 0.01 ? 'Residuo non allocato: € '.number_format($remaining, 2, ',', '.') : null)
                    ->send();
            });
    }

    /**
     * Documenti candidati (stessa direzione, non pagati, in finestra) come opzioni
     * per la CheckboxList di riconciliazione, ordinati per data decrescente.
     *
     * @return array<string, string>
     */
    private static function poolOptions(BankTransaction $record): array
    {
        return app(MatchSuggestionService::class)->candidatePool($record)
            ->sortByDesc(fn (array $c): string => optional($c['date'])->format('Y-m-d') ?? '')
            ->mapWithKeys(fn (array $c): array => [$c['model']->getMorphClass().':'.$c['model']->getKey() => $c['label']])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function poolDescriptions(BankTransaction $record): array
    {
        return app(MatchSuggestionService::class)->candidatePool($record)
            ->mapWithKeys(fn (array $c): array => [
                $c['model']->getMorphClass().':'.$c['model']->getKey() => sprintf(
                    '€ %s · %s',
                    number_format($c['amount'], 2, ',', '.'),
                    optional($c['date'])->format('d/m/Y') ?? '',
                ),
            ])
            ->all();
    }

    /**
     * Mappa chiave-documento → importo, per il totale live.
     *
     * @return array<string, float>
     */
    private static function poolAmounts(BankTransaction $record): array
    {
        return app(MatchSuggestionService::class)->candidatePool($record)
            ->mapWithKeys(fn (array $c): array => [$c['model']->getMorphClass().':'.$c['model']->getKey() => (float) $c['amount']])
            ->all();
    }

    /**
     * Residuo ancora scoperto di un documento (bersaglio meno già riconciliato).
     */
    private static function documentResidual(Model $document): float
    {
        if (! method_exists($document, 'total')) {
            return 0.0;
        }

        $target = $document instanceof Invoice ? $document->amountToCollect() : $document->total();
        $reconciled = method_exists($document, 'reconciledAmount') ? (float) $document->reconciledAmount() : 0.0;

        return round(max(0, $target - $reconciled), 2);
    }

    /**
     * Scorciatoia per le uscite senza documento: crea un Costo dai dati del
     * movimento e lo riconcilia in un colpo solo. Serve nella revisione uno-per-
     * uno dei movimenti (commissioni, imposte, bolli...).
     */
    private static function markAsCostoAction(): Action
    {
        return Action::make('segnaCosto')
            ->label(new HtmlString('Segna come<br>costo'))
            ->icon(Heroicon::OutlinedReceiptPercent)
            ->color('warning')
            ->visible(fn (BankTransaction $record): bool => $record->direction === BankTransaction::DIRECTION_OUT
                && ! $record->isTransfer()
                && $record->unreconciledAmount() > 0.01)
            ->fillForm(fn (BankTransaction $record): array => [
                'description' => str($record->description ?: 'Costo')->limit(80)->value(),
                'amount' => $record->unreconciledAmount(),
            ])
            ->schema([
                TextInput::make('description')
                    ->label('Descrizione')
                    ->required(),
                Select::make('category')
                    ->label('Conto')
                    ->options(fn (): array => self::contoOptions())
                    ->searchable(),
                Select::make('supplier_id')
                    ->label('Fornitore')
                    // Non ->relationship(): il form è sul movimento (BankTransaction),
                    // che non ha una relazione supplier. Lista svincolata dal record.
                    ->options(fn (): array => Supplier::orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                TextInput::make('amount')
                    ->label('Importo')
                    ->numeric()
                    ->prefix('EUR')
                    ->step(0.01)
                    ->required(),
            ])
            ->action(function (array $data, BankTransaction $record): void {
                $costo = Costo::create([
                    'date' => $record->booked_at,
                    'description' => $data['description'],
                    'category' => $data['category'] ?? null,
                    'supplier_id' => $data['supplier_id'] ?? null,
                    'amount' => (float) $data['amount'],
                    'vat_amount' => 0,
                    'bank_transaction_id' => $record->id,
                ]);

                app(ReconciliationService::class)->attach($record, $costo, (float) $data['amount']);

                Notification::make()->success()->title('Costo creato e riconciliato')->send();
            });
    }

    /**
     * Conti già usati (categorie di Fatture in Cloud) su costi e fatture passive,
     * per suggerirli quando si crea un costo dal movimento.
     *
     * @return array<string, string>
     */
    private static function contoOptions(): array
    {
        return Costo::query()->whereNotNull('category')->where('category', '!=', '')->distinct()->pluck('category')
            ->merge(PassiveInvoice::query()->whereNotNull('category')->where('category', '!=', '')->distinct()->pluck('category'))
            ->unique()->sort()->mapWithKeys(fn (string $c): array => [$c => $c])->all();
    }

    /**
     * Rimuove tutte le riconciliazioni del movimento.
     */
    /**
     * Marca il movimento come giroconto / partita di giro, scegliendo uno o più
     * movimenti collegati (segno opposto). Collega tutti i movimenti in un'unica
     * partita di giro (transfer_group_id condiviso). Supporta l'uno-a-molti, es.
     * un rimborso a fronte di più uscite.
     */
    private static function markAsTransferAction(): Action
    {
        return Action::make('segnaGiroconto')
            ->label(new HtmlString('Segna come<br>giroconto'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('gray')
            // Non su un movimento già riconciliato a un documento né già giroconto.
            ->visible(fn (BankTransaction $record): bool => ! $record->isTransfer() && ! $record->reconciled)
            ->modalHeading('Segna come giroconto / partita di giro')
            ->modalDescription(fn (BankTransaction $record): string => sprintf(
                'Movimento del %s — %s € %s. Scegli uno o più movimenti collegati (le altre metà del trasferimento; '
                .'per una partita di giro la somma deve tornare a zero).',
                optional($record->booked_at)->format('d/m/Y') ?? '',
                $record->bankAccount->name ?? '',
                number_format((float) $record->amount, 2, ',', '.'),
            ))
            ->schema([
                Select::make('pair_ids')
                    ->label('Movimenti collegati')
                    ->options(fn (BankTransaction $record): array => self::transferCandidates($record))
                    ->multiple()
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data, BankTransaction $record): void {
                $pairs = BankTransaction::whereIn('id', $data['pair_ids'] ?? [])->get();
                if ($pairs->isEmpty()) {
                    Notification::make()->warning()->title('Nessun movimento collegato selezionato')->send();

                    return;
                }

                app(MovementActions::class)->markAsTransferGroup($pairs->push($record));

                Notification::make()->success()->title('Segnato come giroconto / partita di giro')->send();
            });
    }

    private static function unmarkTransferAction(): Action
    {
        return Action::make('annullaGiroconto')
            ->label(new HtmlString('Annulla<br>giroconto'))
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (BankTransaction $record): bool => $record->isTransfer())
            ->action(function (BankTransaction $record): void {
                app(MovementActions::class)->clearTransferGroup($record);

                Notification::make()->success()->title('Giroconto annullato')->send();
            });
    }

    /**
     * Candidati collegabili in un giroconto / partita di giro: movimenti di segno
     * opposto, non già in una partita di giro, entro ±30 giorni, con l'importo più
     * vicino in cima. Etichetta con data, conto, importo e descrizione.
     *
     * @return array<int, string>
     */
    private static function transferCandidates(BankTransaction $record): array
    {
        $amount = (float) $record->amount;
        $from = $record->booked_at->copy()->subDays(30);
        $to = $record->booked_at->copy()->addDays(30);

        return BankTransaction::query()
            ->with('bankAccount')
            ->whereNull('transfer_group_id')
            ->where('id', '!=', $record->id)
            ->where('amount', $amount < 0 ? '>' : '<', 0)
            ->whereBetween('booked_at', [$from, $to])
            ->get()
            ->sortBy(fn (BankTransaction $t): float => abs(abs((float) $t->amount) - abs($amount)))
            ->take(50)
            ->mapWithKeys(fn (BankTransaction $t): array => [
                $t->id => sprintf(
                    '%s · %s · € %s · %s',
                    optional($t->booked_at)->format('d/m/Y') ?? '',
                    $t->bankAccount->name ?? '',
                    number_format((float) $t->amount, 2, ',', '.'),
                    str($t->description ?: ($t->counterparty ?? ''))->limit(40)->value(),
                ),
            ])
            ->all();
    }

    private static function unreconcileAction(): Action
    {
        return Action::make('annullaRiconciliazione')
            ->label(new HtmlString('Annulla<br>riconciliazione'))
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (BankTransaction $record): bool => $record->reconciled)
            ->action(function (BankTransaction $record): void {
                $service = app(ReconciliationService::class);
                foreach ($record->reconciliations()->get() as $reconciliation) {
                    $service->detach($reconciliation);
                }

                Notification::make()->success()->title('Riconciliazione annullata')->send();
            });
    }

    /**
     * @return array<int|string, string>
     */
    private static function manualOptions(?string $type): array
    {
        return match ($type) {
            'invoice' => Invoice::with('client')->where('status', '!=', 'paid')->latest('issue_date')->limit(100)->get()
                ->mapWithKeys(fn (Invoice $i): array => [$i->id => sprintf('%s — %s (€%s)', $i->number, $i->client->name ?? '—', number_format($i->total(), 2, ',', '.'))])
                ->all(),
            'passive_invoice' => PassiveInvoice::with('supplier')->where('payment_status', '!=', PassiveInvoice::STATUS_PAID)->where('type', '!=', PassiveInvoice::TYPE_CREDIT_NOTE)->latest('document_date')->limit(100)->get()
                ->mapWithKeys(fn (PassiveInvoice $p): array => [$p->id => sprintf('%s — %s (€%s)', $p->number, $p->supplier->name ?? '—', number_format($p->total(), 2, ',', '.'))])
                ->all(),
            // Solo costi/spese non ancora coperti da riconciliazioni: quelli già
            // agganciati a un movimento non devono riproporsi come candidati.
            'costo' => Costo::withSum('reconciliations', 'amount')->latest('date')->limit(100)->get()
                ->filter(fn (Costo $c): bool => (float) ($c->reconciliations_sum_amount ?? 0) + 0.01 < $c->total())
                ->mapWithKeys(fn (Costo $c): array => [$c->id => sprintf('%s (€%s)', $c->description, number_format($c->total(), 2, ',', '.'))])
                ->all(),
            'expense' => Expense::with(['supplier', 'client'])->withSum('reconciliations', 'amount')->whereNull('passive_invoice_id')->latest('date')->limit(100)->get()
                ->filter(fn (Expense $e): bool => (float) ($e->reconciliations_sum_amount ?? 0) + 0.01 < $e->total())
                ->mapWithKeys(fn (Expense $e): array => [$e->id => sprintf(
                    '%s — %s (€%s)',
                    optional($e->date)->format('d/m/Y') ?? '',
                    $e->supplier->name ?? $e->client->name ?? (string) ($e->notes ?? 'Spesa'),
                    number_format($e->total(), 2, ',', '.'),
                )])
                ->all(),
            // Rimborsi spese ancora scoperti: il bonifico che li salda si aggancia qui.
            'reimbursement' => Reimbursement::latest('date')->limit(100)->get()
                ->filter(fn (Reimbursement $r): bool => round($r->total() - $r->reconciledAmount(), 2) > 0.01)
                ->mapWithKeys(fn (Reimbursement $r): array => [$r->id => sprintf(
                    '%s — %s (€%s)',
                    optional($r->date)->format('d/m/Y') ?? '',
                    str($r->notes ?: 'Rimborso spese')->limit(40)->value(),
                    number_format($r->total(), 2, ',', '.'),
                )])
                ->all(),
            default => [],
        };
    }
}
