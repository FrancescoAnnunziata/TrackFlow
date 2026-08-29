<?php

namespace App\Filament\Resources\Expenses\Tables;

use App\Models\BankTransaction;
use App\Models\Expense;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Utente')
                    ->searchable()
                    ->sortable()
                    ->visible(fn (): bool => auth()->user()->isAdmin()),
                TextColumn::make('client.name')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('supplier.name')
                    ->label('Fornitore')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('conto')
                    ->label('Conto')
                    ->badge()
                    ->sortable(),
                TextColumn::make('date')
                    ->label('Data')
                    ->date()
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Importo')
                    ->money('EUR')
                    ->sortable(),
                TextColumn::make('attachaments')
                    ->label('Allegati')
                    ->formatStateUsing(fn ($state): string => is_array($state) ? (string) count($state) : '0'),
                TextColumn::make('bankTransaction.booked_at')
                    ->label('Pagata con')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->tooltip(fn (Expense $record): ?string => $record->bankTransaction?->description)
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('user')
                    ->label('Utente')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->visible(fn (): bool => auth()->user()->isAdmin()),
                SelectFilter::make('client')
                    ->label('Cliente')
                    ->relationship('client', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('date')
                    ->label('Data')
                    ->schema([
                        DatePicker::make('from')->label('Dal'),
                        DatePicker::make('until')->label('Al'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('date', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('date', '<=', $date));
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
                self::collegaMovimentoAction(),
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
     * Collega la spesa al movimento bancario con cui è stata pagata.
     *
     * NON è una riconciliazione: non alloca denaro e non entra in nessun
     * totale. L'uscita resta giustificata dalla fattura passiva o dal costo —
     * agganciarci anche la spesa conterebbe lo stesso denaro due volte. Serve
     * solo a chiudere la catena scontrino → pagamento → riaddebito al cliente.
     */
    private static function collegaMovimentoAction(): Action
    {
        return Action::make('collegaMovimento')
            ->label(fn (Expense $record): string => $record->bank_transaction_id ? 'Cambia movimento' : 'Collega movimento')
            ->icon('heroicon-o-link')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()->isAdmin())
            ->modalHeading('Collega la spesa al pagamento')
            ->modalDescription('Serve solo a sapere con quale uscita è stata pagata: non riconcilia niente e non cambia i totali. La riconciliazione contabile di quel movimento resta separata.')
            ->modalSubmitActionLabel('Collega')
            ->fillForm(fn (Expense $record): array => ['bank_transaction_id' => $record->bank_transaction_id])
            ->schema(fn (Expense $record): array => [
                Select::make('bank_transaction_id')
                    ->label('Movimento bancario')
                    ->options(fn (): array => self::movimentiVicini($record))
                    ->searchable()
                    ->placeholder('Nessuno — scollega')
                    ->helperText('In elenco le uscite dello stesso importo, e quelle vicine per data.'),
            ])
            ->action(function (Expense $record, array $data): void {
                $record->update(['bank_transaction_id' => $data['bank_transaction_id'] ?: null]);

                Notification::make()
                    ->success()
                    ->title($record->bank_transaction_id ? 'Spesa collegata al pagamento' : 'Collegamento rimosso')
                    ->send();
            });
    }

    /**
     * Uscite candidate: prima quelle dell'importo esatto, poi le altre dello
     * stesso periodo. Cercare a mano fra centinaia di movimenti è il motivo per
     * cui un collegamento del genere non si userebbe mai.
     *
     * @return array<int, string>
     */
    private static function movimentiVicini(Expense $expense): array
    {
        $importo = round((float) $expense->amount, 2);
        $data = $expense->date;

        return BankTransaction::query()
            ->where('amount', '<', 0)
            ->when($data !== null, fn (Builder $q) => $q
                ->whereDate('booked_at', '>=', $data->copy()->subDays(20))
                ->whereDate('booked_at', '<=', $data->copy()->addDays(20)))
            ->orderByRaw('ABS(ABS(amount) - ?) ASC', [$importo])
            ->orderBy('booked_at', 'desc')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (BankTransaction $t): array => [$t->id => sprintf(
                '%s — € %s — %s',
                optional($t->booked_at)->format('d/m/Y') ?? '',
                number_format(abs((float) $t->amount), 2, ',', '.'),
                str((string) ($t->description ?: $t->counterparty ?: 'Movimento'))->limit(60)->value(),
            )])
            ->all();
    }
}
