<?php

namespace App\Filament\Resources\BankTransactions\Tables;

use App\Models\BankTransaction;
use App\Models\Costo;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Services\Reconciliation\MatchSuggestionService;
use App\Services\Reconciliation\ReconciliationService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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
                    ->limit(50)
                    ->wrap(),
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
                    ->label('Riconciliato'),
                Filter::make('booked_at')
                    ->label('Data')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('from')->label('Dal'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('Al'),
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
                self::unreconcileAction(),
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
            ->visible(fn (BankTransaction $record): bool => $record->unreconciledAmount() > 0.01)
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
     * Rimuove tutte le riconciliazioni del movimento.
     */
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
            'passive_invoice' => PassiveInvoice::with('supplier')->where('payment_status', '!=', PassiveInvoice::STATUS_PAID)->latest('document_date')->limit(100)->get()
                ->mapWithKeys(fn (PassiveInvoice $p): array => [$p->id => sprintf('%s — %s (€%s)', $p->number, $p->supplier->name ?? '—', number_format($p->total(), 2, ',', '.'))])
                ->all(),
            'costo' => Costo::latest('date')->limit(100)->get()
                ->mapWithKeys(fn (Costo $c): array => [$c->id => sprintf('%s (€%s)', $c->description, number_format($c->total(), 2, ',', '.'))])
                ->all(),
            default => [],
        };
    }
}
