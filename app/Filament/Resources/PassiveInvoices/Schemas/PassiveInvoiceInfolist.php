<?php

namespace App\Filament\Resources\PassiveInvoices\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PassiveInvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Documento')
                    ->columns(2)
                    ->components([
                        TextEntry::make('supplier.name')->label('Fornitore'),
                        TextEntry::make('number')->label('Numero')->placeholder('-'),
                        TextEntry::make('document_date')->label('Data documento')->date(),
                        TextEntry::make('due_date')->label('Scadenza')->date()->placeholder('-'),
                        TextEntry::make('category')->label('Categoria')->placeholder('-'),
                        TextEntry::make('payment_status')
                            ->label('Stato pagamento')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => $state === 'paid' ? 'Pagata' : 'Non pagata')
                            ->color(fn (string $state): string => $state === 'paid' ? 'success' : 'warning'),
                    ]),
                Section::make('Importi')
                    ->columns(3)
                    ->components([
                        TextEntry::make('amount_net')->label('Imponibile')->money('EUR'),
                        TextEntry::make('amount_vat')->label('IVA')->money('EUR'),
                        TextEntry::make('amount_gross')->label('Totale')->money('EUR')->weight('bold'),
                    ]),
                Section::make('Righe')
                    ->components([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->columns(4)
                            ->components([
                                TextEntry::make('name')->label('Voce')->columnSpan(2),
                                TextEntry::make('qty')->label('Q.tà'),
                                TextEntry::make('net_price')->label('Prezzo')->money('EUR'),
                            ]),
                    ])
                    ->visible(fn ($record): bool => $record->items()->exists()),
                TextEntry::make('notes')->label('Note')->placeholder('-')->columnSpanFull(),
            ]);
    }
}
