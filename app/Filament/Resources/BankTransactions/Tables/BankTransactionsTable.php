<?php

namespace App\Filament\Resources\BankTransactions\Tables;

use App\Models\BankTransaction;
use App\Models\Costo;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Services\Reconciliation\MatchSuggestionService;
use App\Services\Reconciliation\ReconciliationService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

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
                    ->extraHeaderAttributes(['style' => 'min-width: 28rem;']),
                TextColumn::make('counterparty')
                    ->label('Controparte')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('amount')
                    ->label('Importo')
                    ->money('EUR')
                    ->color(fn ($record): string => $record->amount >= 0 ? 'success' : 'danger')
                    ->sortable(),
                IconColumn::make('reconciled')
                    ->label('Riconciliato')
                    ->boolean(),
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
                        false: fn (Builder $query): Builder => $query->where('reconciled', false)->whereNull('transfer_pair_id'),
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
            ])
            ->defaultSort('booked_at', 'desc')
            ->recordActions([
                self::reconcileAction(),
                self::markAsCostoAction(),
                self::markAsTransferAction(),
                self::unreconcileAction(),
                self::unmarkTransferAction(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
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
            ->fillForm(fn (BankTransaction $record): array => ['amount' => $record->unreconciledAmount()])
            ->schema([
                Select::make('suggestion')
                    ->label('Suggerimenti')
                    ->helperText('Candidati con importo compatibile, ordinati per confidenza.')
                    ->options(fn (BankTransaction $record): array => app(MatchSuggestionService::class)
                        ->suggestions($record)
                        ->mapWithKeys(fn (array $s): array => [
                            $s['model']->getMorphClass().':'.$s['model']->getKey() => sprintf('%s — €%s (%d%%)', $s['label'], number_format($s['amount'], 2, ',', '.'), $s['confidence']),
                        ])
                        ->all())
                    ->searchable(),
                Section::make('Oppure scegli manualmente')
                    ->columns(2)
                    ->components([
                        Select::make('manual_type')
                            ->label('Tipo documento')
                            ->options([
                                'invoice' => 'Fattura attiva',
                                'passive_invoice' => 'Fattura passiva',
                                'costo' => 'Costo',
                                'expense' => 'Spesa',
                            ])
                            ->live(),
                        Select::make('manual_id')
                            ->label('Documento')
                            ->options(fn (Get $get): array => self::manualOptions($get('manual_type')))
                            ->searchable(),
                    ]),
                TextInput::make('amount')
                    ->label('Importo da allocare')
                    ->numeric()
                    ->prefix('EUR')
                    ->step(0.01)
                    ->required(),
            ])
            ->action(function (array $data, BankTransaction $record): void {
                $key = $data['suggestion']
                    ?: (filled($data['manual_type'] ?? null) && filled($data['manual_id'] ?? null)
                        ? $data['manual_type'].':'.$data['manual_id']
                        : null);

                if ($key === null) {
                    Notification::make()->danger()->title('Nessun documento selezionato')->send();

                    return;
                }

                [$alias, $id] = explode(':', $key, 2);
                $class = Relation::getMorphedModel($alias) ?? $alias;
                $document = $class::find($id);

                if ($document === null) {
                    Notification::make()->danger()->title('Documento non trovato')->send();

                    return;
                }

                app(ReconciliationService::class)->attach(
                    $record,
                    $document,
                    (float) $data['amount'],
                );

                Notification::make()->success()->title('Movimento riconciliato')->send();
            });
    }

    /**
     * Scorciatoia per le uscite senza documento: crea un Costo dai dati del
     * movimento e lo riconcilia in un colpo solo. Serve nella revisione uno-per-
     * uno dei movimenti (commissioni, imposte, bolli...).
     */
    private static function markAsCostoAction(): Action
    {
        return Action::make('segnaCosto')
            ->label('Segna come costo')
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
                    ->relationship('supplier', 'name')
                    ->searchable()
                    ->preload(),
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
     * Marca il movimento come giroconto verso un altro conto, scegliendo il
     * movimento gemello (importo opposto su un conto diverso). Collega i due in
     * modo reciproco (transfer_pair_id).
     */
    private static function markAsTransferAction(): Action
    {
        return Action::make('segnaGiroconto')
            ->label('Segna come giroconto')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('gray')
            // Non su un movimento già riconciliato a un documento né già giroconto.
            ->visible(fn (BankTransaction $record): bool => ! $record->isTransfer() && ! $record->reconciled)
            ->modalHeading('Segna come giroconto')
            ->modalDescription(fn (BankTransaction $record): string => sprintf(
                'Movimento del %s — %s € %s. Scegli il movimento gemello (l\'altra metà del trasferimento).',
                optional($record->booked_at)->format('d/m/Y') ?? '',
                $record->bankAccount->name ?? '',
                number_format((float) $record->amount, 2, ',', '.'),
            ))
            ->schema([
                Select::make('pair_id')
                    ->label('Movimento gemello')
                    ->options(fn (BankTransaction $record): array => self::transferCandidates($record))
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data, BankTransaction $record): void {
                $pair = BankTransaction::find($data['pair_id']);
                if ($pair === null) {
                    Notification::make()->warning()->title('Movimento gemello non trovato')->send();

                    return;
                }

                $record->update(['transfer_pair_id' => $pair->id]);
                $pair->update(['transfer_pair_id' => $record->id]);

                Notification::make()->success()->title('Segnato come giroconto')->send();
            });
    }

    private static function unmarkTransferAction(): Action
    {
        return Action::make('annullaGiroconto')
            ->label('Annulla giroconto')
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (BankTransaction $record): bool => $record->isTransfer())
            ->action(function (BankTransaction $record): void {
                $pair = $record->transferPair;
                $record->update(['transfer_pair_id' => null]);
                $pair?->update(['transfer_pair_id' => null]);

                Notification::make()->success()->title('Giroconto annullato')->send();
            });
    }

    /**
     * Candidati come gemello di un giroconto: movimenti di segno opposto su un
     * conto diverso, non già marcati, entro ±30 giorni, con l'importo più vicino
     * in cima. Etichetta con data, conto, importo e descrizione.
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
            ->whereNull('transfer_pair_id')
            ->where('bank_account_id', '!=', $record->bank_account_id)
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
            ->label('Annulla riconciliazione')
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
            'costo' => Costo::latest('date')->limit(100)->get()
                ->mapWithKeys(fn (Costo $c): array => [$c->id => sprintf('%s (€%s)', $c->description, number_format($c->total(), 2, ',', '.'))])
                ->all(),
            'expense' => Expense::with(['supplier', 'client'])->whereNull('passive_invoice_id')->latest('date')->limit(100)->get()
                ->mapWithKeys(fn (Expense $e): array => [$e->id => sprintf(
                    '%s — %s (€%s)',
                    optional($e->date)->format('d/m/Y') ?? '',
                    $e->supplier->name ?? $e->client->name ?? (string) ($e->notes ?? 'Spesa'),
                    number_format($e->total(), 2, ',', '.'),
                )])
                ->all(),
            default => [],
        };
    }
}
