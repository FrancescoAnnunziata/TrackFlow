<?php

namespace App\Filament\Resources\PassiveInvoices\Tables;

use App\Models\BankTransaction;
use App\Models\PassiveInvoice;
use App\Services\Reconciliation\ReconciliationService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Illuminate\Support\Collection;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PassiveInvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('supplier.name')
                    ->label('Fornitore')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('number')
                    ->label('Numero')
                    ->searchable(),
                TextColumn::make('document_date')
                    ->label('Data')
                    ->date()
                    ->sortable(),
                TextColumn::make('amount_gross')
                    ->label('Totale')
                    ->money('EUR')
                    ->sortable(),
                TextColumn::make('payment_status')
                    ->label('Pagamento')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'paid' ? 'Pagata' : 'Non pagata')
                    ->color(fn (string $state): string => $state === 'paid' ? 'success' : 'warning'),
                IconColumn::make('reconciled')
                    ->label('Riconciliata')
                    ->state(fn ($record): bool => $record->reconciliations()->exists())
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('supplier')
                    ->label('Fornitore')
                    ->relationship('supplier', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('payment_status')
                    ->label('Stato pagamento')
                    ->options([
                        'not_paid' => 'Non pagata',
                        'paid' => 'Pagata',
                    ]),
                Filter::make('document_date')
                    ->label('Data')
                    ->schema([
                        DatePicker::make('from')->label('Dal'),
                        DatePicker::make('until')->label('Al'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('document_date', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('document_date', '<=', $date));
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
            ->defaultSort('document_date', 'desc')
            ->recordActions([
                self::reconcileAction(),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Segna la fattura come pagata scegliendo il/i movimento/i bancario/i che la
     * coprono, da un elenco pre-filtrato (±45 giorni, importo ±50%). Multiselect
     * per i pagamenti spezzati (una fattura coperta da più movimenti).
     */
    private static function reconcileAction(): Action
    {
        return Action::make('riconcilia')
            ->label('Segna pagata')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (PassiveInvoice $record): bool => ! $record->isPaid())
            ->modalHeading('Segna pagata')
            ->modalDescription(fn (PassiveInvoice $record): string => sprintf(
                'Fattura %s — %s — %s %s — %s',
                $record->number ?: '(s.n.)',
                $record->supplier->name ?? '—',
                $record->currency ?: 'EUR',
                number_format((float) $record->amount_gross, 2, ',', '.'),
                optional($record->document_date)->format('d/m/Y') ?? '',
            ))
            ->modalSubmitActionLabel('Segna pagata')
            ->schema([
                CheckboxList::make('transactions')
                    ->label('Seleziona il/i movimento/i bancario/i che pagano questa fattura')
                    ->helperText('Uscite entro ±45 giorni dalla data documento e importo entro ±50% del totale. Selezionane più di uno per un pagamento a rate.')
                    ->options(fn (PassiveInvoice $record): array => self::candidateOptions($record))
                    ->descriptions(fn (PassiveInvoice $record): array => self::candidateDescriptions($record))
                    ->searchable()
                    ->bulkToggleable()
                    ->noSearchResultsMessage('Nessun movimento compatibile trovato.')
                    ->required(),
            ])
            ->action(function (array $data, PassiveInvoice $record): void {
                $service = app(ReconciliationService::class);
                $remaining = round($record->total() - $record->reconciledAmount(), 2);
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
                    // lo spezzato sia il movimento cumulativo, di cui prende solo la parte).
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
                    ->title($record->fresh()->isPaid() ? 'Fattura segnata pagata' : 'Riconciliazione parziale registrata')
                    ->send();
            });
    }

    /**
     * Movimenti candidati per una fattura passiva: uscite con quota ancora da
     * allocare, entro ±45 giorni dalla data documento e importo entro ±50% del
     * totale della fattura. Ordinati per vicinanza d'importo.
     *
     * @return Collection<int, BankTransaction>
     */
    private static function candidateTransactions(PassiveInvoice $record): Collection
    {
        $total = (float) $record->amount_gross;
        $from = $record->document_date->copy()->subDays(45);
        $to = $record->document_date->copy()->addDays(45);

        return BankTransaction::query()
            ->with('bankAccount')
            ->where('amount', '<', 0)
            ->whereBetween('booked_at', [$from, $to])
            ->whereRaw('ABS(amount) BETWEEN ? AND ?', [$total * 0.5, $total * 1.5])
            ->orderBy('booked_at')
            ->get()
            ->filter(fn (BankTransaction $tx): bool => $tx->unreconciledAmount() > 0.01)
            ->sortBy(fn (BankTransaction $tx): float => abs(abs((float) $tx->amount) - $total))
            ->values();
    }

    /**
     * Etichetta "colonna" di ogni movimento nella lista di scelta: data e importo.
     *
     * @return array<int, string>
     */
    private static function candidateOptions(PassiveInvoice $record): array
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
    private static function candidateDescriptions(PassiveInvoice $record): array
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
