<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Models\InvoiceItem;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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
                            ->maxLength(50)
                            ->placeholder('Assegnato da Fatture in Cloud')
                            ->helperText('Vuoto per i clienti FIC (lo assegna FIC all\'invio). Per Fiscozen/esterni scrivilo a mano.'),
                        DatePicker::make('issue_date')
                            ->label('Data emissione')
                            ->required()
                            ->default(now()),
                        Select::make('client_id')
                            ->label('Cliente')
                            ->relationship('client', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
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

                Section::make('Periodo')
                    ->columns(3)
                    ->components([
                        DatePicker::make('period_from')
                            ->label('Periodo dal')
                            ->required()
                            ->default(now()->startOfMonth()),
                        DatePicker::make('period_to')
                            ->label('Periodo al')
                            ->required()
                            ->default(now()->endOfMonth()),
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
                    ->description('Le righe che finiscono in fattura: generate dal motore ("Genera fattura" da cliente e periodo), qui le puoi rivedere e modificare. Le spese vanno in art. 15.')
                    ->components([
                        Repeater::make('items')
                            ->label('')
                            ->relationship()
                            ->columns(12)
                            ->schema([
                                TextInput::make('name')
                                    ->label('Descrizione')
                                    ->required()
                                    ->columnSpan(6),
                                TextInput::make('qty')
                                    ->label('Q.tà')
                                    ->numeric()
                                    ->default(1)
                                    ->required()
                                    ->columnSpan(2),
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
                                    ->columnSpan(1),
                            ])
                            ->orderColumn('sort')
                            ->addActionLabel('Aggiungi riga')
                            ->collapsible(),
                    ]),

                InvoiceExpensesDetail::section(),

                Section::make('Note')
                    ->components([
                        Textarea::make('notes')
                            ->label('')
                            ->rows(3)
                            ->helperText('Note aggiuntive tue. Il dettaglio delle spese non va scritto qui: viene composto da solo all\'invio, dalle spese agganciate qui sopra.'),
                    ]),
            ])
            // Sezioni impilate a piena larghezza: le righe fattura hanno tutto
            // lo spazio (altrimenti Prezzo/Q.tà si stringono in mezza colonna).
            ->columns(1);
    }
}
