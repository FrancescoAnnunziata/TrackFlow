<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Models\Client;
use App\Models\InvoiceItem;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                        // Il testo cambia col cliente: la numerazione non la
                        // decide mai TrackFlow, ma chi emette davvero la fattura
                        // (FIC via API, Fiscozen o altro gestionale a mano).
                        TextInput::make('number')
                            ->label('Numero')
                            ->maxLength(50)
                            ->placeholder(fn (Get $get): string => self::billableHere($get)
                                ? 'Assegnato da Fatture in Cloud'
                                : 'Numero assegnato da '.self::providerLabel($get))
                            ->helperText(fn (Get $get): string => self::billableHere($get)
                                ? 'Lascialo vuoto: lo assegna Fatture in Cloud al momento dell\'invio.'
                                : 'Lo assegna '.self::providerLabel($get).', dove emetti davvero la fattura: riportalo qui a mano, oppure caricane il PDF da "Fatture emesse da PDF".'),
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
                            // Il testo del campo Numero dipende dal provider.
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

    /**
     * Cliente selezionato nella form, se già scelto.
     */
    private static function selectedClient(Get $get): ?Client
    {
        $id = $get('client_id');

        return filled($id) ? Client::find($id) : null;
    }

    /**
     * True se la fattura si emette da TrackFlow (cliente Fatture in Cloud).
     * Senza cliente scelto si assume di sì: è il caso più comune.
     */
    private static function billableHere(Get $get): bool
    {
        return self::selectedClient($get)?->isBillableHere() ?? true;
    }

    private static function providerLabel(Get $get): string
    {
        return self::selectedClient($get)?->invoicingProviderLabel() ?? 'il gestionale esterno';
    }
}
