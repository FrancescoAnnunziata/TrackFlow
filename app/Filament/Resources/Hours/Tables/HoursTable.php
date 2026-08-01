<?php

namespace App\Filament\Resources\Hours\Tables;

use App\Models\Hour;
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

class HoursTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.email')
                    ->label('Operatore')
                    ->searchable()
                    ->sortable()
                    ->visible(fn (): bool => auth()->user()->isAdmin() || auth()->user()->isClient()),
                TextColumn::make('clients.name')
                    ->label('Clienti')
                    ->badge()
                    ->searchable(),
                TextColumn::make('date')
                    ->date()
                    ->sortable(),
                TextColumn::make('hours')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('billable')
                    ->boolean(),
                TextColumn::make('notes')
                    ->label('Note')
                    ->limit(50)
                    ->tooltip(fn (Hour $record): ?string => $record->notes)
                    ->copyable()
                    ->copyMessage('Nota copiata')
                    ->searchable()
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
                    // Le ore possono essere registrate solo da admin o member:
                    // il filtro elenca quindi solo questi ruoli, per email.
                    ->relationship(
                        'user',
                        'email',
                        fn (Builder $query): Builder => $query->whereIn('role', ['admin', 'member'])
                    )
                    ->searchable()
                    ->preload()
                    ->visible(fn (): bool => auth()->user()->isAdmin()),
                SelectFilter::make('operatore')
                    ->label('Operatore')
                    ->relationship(
                        'user',
                        'email',
                        fn (Builder $query): Builder => $query
                            ->whereIn('role', ['admin', 'member'])
                            ->whereHas(
                                'hours.clients',
                                fn (Builder $q): Builder => $q->whereKey(auth()->user()->allClientIds())
                            )
                    )
                    ->searchable()
                    ->preload()
                    ->visible(fn (): bool => auth()->user()->isClient()),
                SelectFilter::make('clients')
                    ->label('Cliente')
                    ->relationship('clients', 'name')
                    ->searchable()
                    ->preload()
                    ->visible(fn (): bool => ! auth()->user()->isClient()),
                SelectFilter::make('invoice')
                    ->label('Fattura')
                    // Solo fatture con un numero: le bozze non ancora inviate (numero
                    // assegnato da Fatture in Cloud) darebbero una label null e farebbero
                    // fallire il Select, oltre a non avere senso come opzione di filtro.
                    ->relationship(
                        'invoices',
                        'number',
                        fn (Builder $query): Builder => $query
                            ->whereNotNull('number')
                            ->where('number', '!=', '')
                            ->when(
                                auth()->user()->isClient(),
                                fn (Builder $q): Builder => $q->whereIn('client_id', auth()->user()->allClientIds())
                            )
                    )
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
