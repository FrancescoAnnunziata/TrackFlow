<?php

namespace App\Filament\Resources\Reimbursements\Tables;

use App\Enums\ReimbursementStatus;
use App\Enums\ReimbursementType;
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

class ReimbursementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Utente')
                    ->searchable()
                    ->sortable()
                    ->visible(fn (): bool => auth()->user()->isAdmin()),
                TextColumn::make('type')
                    ->label('Tipologia')
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
                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->sortable(),
                IconColumn::make('expense_id')
                    ->label('Da spesa')
                    ->boolean()
                    ->tooltip('Generato automaticamente da una spesa con carta personale'),
                TextColumn::make('client.name')
                    ->label('Cliente')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('attachments')
                    ->label('Allegati')
                    ->formatStateUsing(fn ($state): string => is_array($state) ? (string) count($state) : '0'),
                TextColumn::make('created_at')
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
                SelectFilter::make('type')
                    ->label('Tipologia')
                    ->options(ReimbursementType::class),
                SelectFilter::make('status')
                    ->label('Stato')
                    ->options(ReimbursementStatus::class),
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
