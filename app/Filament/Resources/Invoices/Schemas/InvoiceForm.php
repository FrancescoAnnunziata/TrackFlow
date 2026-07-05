<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Intestazione')
                    ->columns(2)
                    ->components([
                        TextInput::make('number')
                            ->label('Numero')
                            ->required()
                            ->maxLength(50)
                            ->default(fn () => Invoice::suggestNextNumber()),
                        DatePicker::make('issue_date')
                            ->label('Data emissione')
                            ->required()
                            ->default(now()),
                        Select::make('client_id')
                            ->label('Cliente')
                            ->relationship('client', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live(),
                        Select::make('status')
                            ->label('Stato')
                            ->options([
                                'draft' => 'Bozza',
                                'sent' => 'Inviata',
                                'paid' => 'Pagata',
                            ])
                            ->default('draft')
                            ->required(),
                    ]),

                Section::make('Periodo e tariffe')
                    ->columns(2)
                    ->components([
                        DatePicker::make('period_from')
                            ->label('Periodo dal')
                            ->required()
                            ->default(now()->startOfMonth())
                            ->live(),
                        DatePicker::make('period_to')
                            ->label('Periodo al')
                            ->required()
                            ->default(now()->endOfMonth())
                            ->live(),
                        TextInput::make('hourly_rate')
                            ->label('Tariffa oraria (€/h)')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->required(),
                        TextInput::make('vat_rate')
                            ->label('IVA (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.01)
                            ->default(22)
                            ->required(),
                    ]),

                Section::make('Righe fattura')
                    ->description('Righe effettive che finiscono in fattura (generate dal motore, modificabili). Se presenti, sono la fonte dei totali e del payload FIC.')
                    ->components([
                        Repeater::make('items')
                            ->label('')
                            ->relationship()
                            ->columns(12)
                            ->schema([
                                TextInput::make('name')
                                    ->label('Descrizione')
                                    ->required()
                                    ->columnSpan(5),
                                TextInput::make('qty')
                                    ->label('Q.tà')
                                    ->numeric()
                                    ->default(1)
                                    ->required()
                                    ->columnSpan(1),
                                TextInput::make('measure')
                                    ->label('U.m.')
                                    ->maxLength(8)
                                    ->columnSpan(1),
                                TextInput::make('net_price')
                                    ->label('Prezzo')
                                    ->numeric()
                                    ->step(0.01)
                                    ->required()
                                    ->columnSpan(2),
                                Select::make('vat_kind')
                                    ->label('IVA')
                                    ->options([
                                        InvoiceItem::VAT_STANDARD => 'Standard',
                                        InvoiceItem::VAT_ART15 => 'Art. 15',
                                    ])
                                    ->default(InvoiceItem::VAT_STANDARD)
                                    ->required()
                                    ->columnSpan(3),
                            ])
                            ->orderColumn('sort')
                            ->addActionLabel('Aggiungi riga')
                            ->collapsible(),
                    ]),

                Section::make('Ore da fatturare')
                    ->description('Ore non ancora fatturate del cliente nel periodo selezionato. Puoi deselezionare quelle da escludere.')
                    ->components([
                        CheckboxList::make('hours')
                            ->label('')
                            ->relationship(
                                'hours',
                                'date',
                                modifyQueryUsing: fn (Builder $query, Get $get, ?Invoice $record) => self::scopeBillable($query, $get, $record),
                            )
                            ->getOptionLabelFromRecordUsing(fn ($record): string => sprintf(
                                '%s — %s — %s h%s',
                                $record->date?->format('d/m/Y') ?? '—',
                                $record->user?->name ?? '—',
                                number_format((float) $record->hours, 1, ',', '.'),
                                $record->notes ? ' — '.str($record->notes)->limit(60) : '',
                            ))
                            ->bulkToggleable()
                            ->columns(1),
                    ])
                    ->visible(fn (Get $get): bool => filled($get('client_id')) && filled($get('period_from')) && filled($get('period_to'))),

                Section::make('Spese da fatturare')
                    ->description('Spese non ancora fatturate del cliente nel periodo selezionato.')
                    ->components([
                        CheckboxList::make('expenses')
                            ->label('')
                            ->relationship(
                                'expenses',
                                'date',
                                modifyQueryUsing: fn (Builder $query, Get $get, ?Invoice $record) => self::scopeBillableExpenses($query, $get, $record),
                            )
                            ->getOptionLabelFromRecordUsing(fn ($record): string => sprintf(
                                '%s — %s — %s €%s',
                                $record->date?->format('d/m/Y') ?? '—',
                                $record->user?->name ?? '—',
                                number_format((float) $record->amount, 2, ',', '.'),
                                $record->notes ? ' — '.str($record->notes)->limit(60) : '',
                            ))
                            ->bulkToggleable()
                            ->columns(1),
                    ])
                    ->visible(fn (Get $get): bool => filled($get('client_id')) && filled($get('period_from')) && filled($get('period_to'))),

                Section::make('Note')
                    ->components([
                        Textarea::make('notes')
                            ->label('')
                            ->rows(3),
                    ]),
            ]);
    }

    private static function scopeBillable(Builder $query, Get $get, ?Invoice $record): Builder
    {
        $clientId = $get('client_id');
        $from = $get('period_from');
        $to = $get('period_to');

        $query->whereHas('clients', fn (Builder $q) => $q->whereKey($clientId));

        if ($from) {
            $query->whereDate('hours.date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('hours.date', '<=', $to);
        }

        return $query->where(function (Builder $q) use ($record) {
            $q->whereDoesntHave('invoices');

            if ($record) {
                $q->orWhereHas('invoices', fn (Builder $qi) => $qi->whereKey($record->id));
            }
        });
    }

    private static function scopeBillableExpenses(Builder $query, Get $get, ?Invoice $record): Builder
    {
        $clientId = $get('client_id');
        $from = $get('period_from');
        $to = $get('period_to');

        $query
            ->where('expenses.client_id', $clientId)
            ->when($from, fn (Builder $q) => $q->whereDate('expenses.date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('expenses.date', '<=', $to));

        return $query->where(function (Builder $q) use ($record) {
            $q->whereDoesntHave('invoices');

            if ($record) {
                $q->orWhereHas('invoices', fn (Builder $qi) => $qi->whereKey($record->id));
            }
        });
    }
}
