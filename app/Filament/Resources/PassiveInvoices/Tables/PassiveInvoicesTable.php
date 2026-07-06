<?php

namespace App\Filament\Resources\PassiveInvoices\Tables;

use Carbon\Carbon;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
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
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
