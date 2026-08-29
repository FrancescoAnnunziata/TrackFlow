<?php

namespace App\Filament\Resources\Corrispettivi\Tables;

use App\Models\Corrispettivo;
use Carbon\Carbon;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CorrispettiviTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date')
                    ->label('Giorno')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('channel')
                    ->label('Origine')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => Corrispettivo::channelLabel($state))
                    ->color(fn (?string $state) => $state === Corrispettivo::CHANNEL_SHOPIFY ? 'success' : 'gray')
                    ->toggleable(),
                TextColumn::make('orders_count')
                    ->label('Ordini')
                    ->alignRight()
                    ->toggleable(),
                TextColumn::make('gross')
                    ->label('Lordo')
                    ->money('EUR')
                    ->sortable()
                    ->summarize(Sum::make()->label('Totale')->money('EUR')),
                TextColumn::make('refunds')
                    ->label('Resi')
                    ->money('EUR')
                    ->sortable()
                    ->summarize(Sum::make()->label('Totale')->money('EUR')),
                TextColumn::make('net')
                    ->label('Netto')
                    ->money('EUR')
                    ->weight('bold')
                    ->description('conta per la soglia'),
                TextColumn::make('synced_at')
                    ->label('Sincronizzato')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('channel')
                    ->label('Origine')
                    ->options([
                        Corrispettivo::CHANNEL_SHOPIFY => 'Shopify',
                        Corrispettivo::CHANNEL_MANUAL => 'Manuale',
                    ]),
                Filter::make('date')
                    ->label('Periodo')
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
            ->defaultSort('date', 'desc')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
