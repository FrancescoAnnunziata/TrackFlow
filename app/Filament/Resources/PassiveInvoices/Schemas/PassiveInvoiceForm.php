<?php

namespace App\Filament\Resources\PassiveInvoices\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PassiveInvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Documento')
                    ->columns(2)
                    ->components([
                        Select::make('supplier_id')
                            ->label('Fornitore')
                            ->relationship(name: 'supplier', titleAttribute: 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('number')
                            ->label('Numero'),
                        DatePicker::make('document_date')
                            ->label('Data documento')
                            ->default(now())
                            ->required(),
                        DatePicker::make('due_date')
                            ->label('Scadenza'),
                        TextInput::make('category')
                            ->label('Categoria di costo'),
                        Select::make('payment_status')
                            ->label('Stato pagamento')
                            ->options([
                                'not_paid' => 'Non pagata',
                                'paid' => 'Pagata',
                            ])
                            ->default('not_paid')
                            ->required(),
                    ]),
                Section::make('Importi')
                    ->columns(3)
                    ->components([
                        TextInput::make('amount_net')
                            ->label('Imponibile')
                            ->numeric()
                            ->prefix('EUR')
                            ->step(0.01)
                            ->default(0)
                            ->required(),
                        TextInput::make('amount_vat')
                            ->label('IVA')
                            ->numeric()
                            ->prefix('EUR')
                            ->step(0.01)
                            ->default(0)
                            ->required(),
                        TextInput::make('amount_gross')
                            ->label('Totale')
                            ->numeric()
                            ->prefix('EUR')
                            ->step(0.01)
                            ->default(0)
                            ->required(),
                    ]),
                Textarea::make('notes')
                    ->label('Note')
                    ->columnSpanFull(),
            ]);
    }
}
