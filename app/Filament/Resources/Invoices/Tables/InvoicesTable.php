<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Filament\Resources\Hours\HourResource;
use App\Models\BankTransaction;
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
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
                    ->searchable()
                    ->sortable(),
                TextColumn::make('total')
                    ->label('Totale')
                    ->state(fn ($record): float => $record->total())
                    ->money('EUR')
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
                    ->toggleable(),
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
     * Segna la fattura attiva come incassata scegliendo il/i movimento/i bancario/i
     * in entrata che la coprono, da un elenco pre-filtrato (±45 giorni dalla data di
     * emissione, importo ±50%). Multiselect per gli incassi spezzati (una fattura
     * coperta da più accrediti). Alla copertura lo stato passa a "Pagata".
     */
    private static function reconcileAction(): Action
    {
        return Action::make('registra_incasso')
            ->label('Registra incasso')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (Invoice $record): bool => $record->status === 'sent')
            ->modalHeading('Registra incasso')
            ->modalDescription(fn (Invoice $record): string => sprintf(
                'Fattura %s — %s — € %s — %s',
                $record->number ?: '(s.n.)',
                $record->client->name ?? '—',
                number_format($record->total(), 2, ',', '.'),
                optional($record->issue_date)->format('d/m/Y') ?? '',
            ))
            ->modalSubmitActionLabel('Registra incasso')
            ->schema([
                CheckboxList::make('transactions')
                    ->label('Seleziona il/i movimento/i in entrata che incassano questa fattura')
                    ->helperText('Entrate entro ±45 giorni dalla data di emissione e importo entro ±50% del totale. Selezionane più di uno per un incasso a rate.')
                    ->options(fn (Invoice $record): array => self::candidateOptions($record))
                    ->descriptions(fn (Invoice $record): array => self::candidateDescriptions($record))
                    ->searchable()
                    ->bulkToggleable()
                    ->noSearchResultsMessage('Nessun movimento compatibile trovato.')
                    ->required(),
            ])
            ->action(function (array $data, Invoice $record): void {
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
     * Movimenti candidati per una fattura attiva: entrate con quota ancora da
     * allocare, entro ±45 giorni dalla data di emissione e importo entro ±50% del
     * totale della fattura. Ordinati per vicinanza d'importo.
     *
     * @return Collection<int, BankTransaction>
     */
    private static function candidateTransactions(Invoice $record): Collection
    {
        $total = $record->total();
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
