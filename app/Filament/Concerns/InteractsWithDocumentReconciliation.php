<?php

namespace App\Filament\Concerns;

use App\Filament\Resources\BankTransactions\BankTransactionResource;
use App\Models\BankTransaction;
use App\Models\Invoice;
use App\Services\Reconciliation\ReconciliationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * Azioni condivise dalle view delle fatture (attive e passive) per riconciliare
 * il documento a un movimento bancario e per elencare le riconciliazioni già
 * collegate. La direzione del movimento dipende dal tipo di documento: le
 * fatture attive si incassano (entrate), le passive si pagano (uscite).
 */
trait InteractsWithDocumentReconciliation
{
    /**
     * Riconcilia il documento a un movimento bancario scelto da un elenco.
     * Visibile solo se resta una quota da riconciliare.
     */
    protected function reconcileDocumentAction(): Action
    {
        return Action::make('reconcileDocument')
            ->label('Riconcilia con movimento')
            ->icon(Heroicon::OutlinedLink)
            ->color('info')
            ->visible(fn (Model $record): bool => $this->documentRemaining($record) > 0.01)
            ->modalHeading('Riconcilia con un movimento bancario')
            ->fillForm(fn (Model $record): array => ['amount' => $this->documentRemaining($record)])
            ->schema([
                Select::make('bank_transaction_id')
                    ->label('Movimento bancario')
                    ->helperText('Movimenti non riconciliati, ordinati per importo più vicino.')
                    ->options(fn (Model $record): array => $this->bankTransactionOptions($record))
                    ->searchable()
                    ->required(),
                TextInput::make('amount')
                    ->label('Importo da allocare')
                    ->numeric()
                    ->prefix('EUR')
                    ->step(0.01)
                    ->required(),
            ])
            ->action(function (array $data, Model $record): void {
                $transaction = BankTransaction::find($data['bank_transaction_id']);

                if ($transaction === null) {
                    Notification::make()->danger()->title('Movimento non trovato')->send();

                    return;
                }

                app(ReconciliationService::class)->attach($transaction, $record, (float) $data['amount']);

                Notification::make()->success()->title('Documento riconciliato')->send();
            });
    }

    /**
     * Mostra i movimenti bancari a cui il documento è riconciliato, con la quota
     * allocata e un link per aprire ciascun movimento. Visibile solo se esiste
     * almeno una riconciliazione.
     */
    protected function viewDocumentReconciliationsAction(): Action
    {
        return Action::make('viewDocumentReconciliations')
            ->label('Riconciliazioni collegate')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (Model $record): bool => $record->reconciliations()->exists())
            ->modalHeading('Movimenti riconciliati')
            ->modalWidth(Width::Large)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Chiudi')
            ->modalContent(function (Model $record) {
                $rows = $record->reconciliations()
                    ->with('bankTransaction.bankAccount')
                    ->get()
                    ->map(function ($r): array {
                        $tx = $r->bankTransaction;

                        return [
                            'label' => $tx !== null ? sprintf(
                                '%s · %s · € %s · %s',
                                optional($tx->booked_at)->format('d/m/Y') ?? '',
                                $tx->bankAccount->name ?? '',
                                number_format((float) $tx->amount, 2, ',', '.'),
                                str($tx->description ?: ($tx->counterparty ?? ''))->limit(50)->value(),
                            ) : 'Movimento non disponibile',
                            'amount' => (float) $r->amount,
                            'matchedBy' => $r->matched_by,
                            'url' => $tx !== null ? BankTransactionResource::getUrl('edit', ['record' => $tx]) : null,
                        ];
                    })
                    ->all();

                return view('filament.documents.reconciliation-list', [
                    'rows' => $rows,
                    'remaining' => $this->documentRemaining($record),
                ]);
            });
    }

    /**
     * Quota del documento ancora da riconciliare (0 se coperto entro tolleranza).
     */
    private function documentRemaining(Model $record): float
    {
        $target = $record instanceof Invoice ? $record->amountToCollect() : $record->total();
        $tolerance = max(0.01, min(1.0, $target * 0.02));

        if ($record->reconciledAmount() + $tolerance >= $target) {
            return 0.0;
        }

        return round($target - $record->reconciledAmount(), 2);
    }

    /**
     * Candidati movimenti bancari per la riconciliazione: stessa direzione del
     * documento (entrate per le attive, uscite per le passive), non riconciliati
     * e non giroconti, con l'importo più vicino in cima.
     *
     * @return array<int, string>
     */
    private function bankTransactionOptions(Model $record): array
    {
        $target = $record instanceof Invoice ? $record->amountToCollect() : $record->total();
        $direction = $record instanceof Invoice ? BankTransaction::DIRECTION_IN : BankTransaction::DIRECTION_OUT;

        return BankTransaction::query()
            ->with('bankAccount')
            ->where('direction', $direction)
            ->where('reconciled', false)
            ->whereNull('transfer_pair_id')
            ->get()
            ->sortBy(fn (BankTransaction $t): float => abs(abs((float) $t->amount) - abs($target)))
            ->take(100)
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
}
