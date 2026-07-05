<?php

namespace App\Filament\Resources\Invoices\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Intestazione')
                    ->columns(2)
                    ->components([
                        TextEntry::make('number')->label('Numero'),
                        TextEntry::make('issue_date')->label('Data emissione')->date(),
                        TextEntry::make('client.name')->label('Cliente'),
                        TextEntry::make('status')
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
                            }),
                    ]),

                Section::make('Periodo e tariffe')
                    ->columns(2)
                    ->components([
                        TextEntry::make('period_from')->label('Dal')->date(),
                        TextEntry::make('period_to')->label('Al')->date(),
                        TextEntry::make('hourly_rate')->label('Tariffa oraria')->money('EUR'),
                        TextEntry::make('vat_rate')->label('IVA')->suffix('%'),
                    ]),

                Section::make('Totali')
                    ->columns(3)
                    ->components([
                        TextEntry::make('taxable_amount')
                            ->label('Imponibile')
                            ->state(fn ($record): float => $record->taxableAmount())
                            ->money('EUR'),
                        TextEntry::make('vat_amount')
                            ->label('IVA')
                            ->state(fn ($record): float => $record->vatAmount())
                            ->money('EUR'),
                        TextEntry::make('total')
                            ->label('Totale')
                            ->state(fn ($record): float => $record->total())
                            ->money('EUR')
                            ->weight('bold'),
                    ]),

                Section::make('Fatture in Cloud')
                    ->columns(2)
                    ->components([
                        TextEntry::make('fic_sent_at')
                            ->label('Stato')
                            ->state(fn ($record): string => $record->isSentToFic() ? 'Inviata' : 'Non inviata')
                            ->badge()
                            ->color(fn ($record): string => $record->isSentToFic() ? 'success' : 'gray'),
                        TextEntry::make('fic_sent_at_when')
                            ->label('Inviata il')
                            ->state(fn ($record) => $record->fic_sent_at)
                            ->dateTime()
                            ->placeholder('—'),
                    ]),

                Section::make('Note')
                    ->components([
                        TextEntry::make('notes')->label('')->placeholder('—'),
                    ]),
            ]);
    }
}
