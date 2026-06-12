<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Filament\Resources\Hours\HourResource;
use App\Models\Invoice;
use Carbon\Carbon;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('Numero')
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
                            $indicators[] = Indicator::make('Dal ' . Carbon::parse($data['from'])->toFormattedDateString())
                                ->removeField('from');
                        }
                        if ($data['until'] ?? null) {
                            $indicators[] = Indicator::make('Al ' . Carbon::parse($data['until'])->toFormattedDateString())
                                ->removeField('until');
                        }

                        return $indicators;
                    }),
            ])
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
