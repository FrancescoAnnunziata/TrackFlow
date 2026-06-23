<?php

namespace App\Filament\Resources\Quotes\Schemas;

use App\Filament\Resources\Quotes\Schemas\QuoteForm;
use App\Models\Quote;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class QuoteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Intestazione')
                    ->columns(2)
                    ->components([
                        TextEntry::make('number')->label('Numero'),
                        TextEntry::make('issue_date')->label('Data')->date(),
                        TextEntry::make('client.name')->label('Cliente'),
                        TextEntry::make('status')
                            ->label('Stato')
                            ->formatStateUsing(fn (string $state): string => QuoteForm::statusOptions()[$state] ?? $state)
                            ->badge()
                            ->color(fn (string $state): string => QuoteForm::statusColor($state)),
                    ]),

                Section::make('Intervento e stima')
                    ->columns(2)
                    ->components([
                        TextEntry::make('description')
                            ->label('Descrizione intervento')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('estimated_hours')->label('Ore stimate')->suffix(' h'),
                        TextEntry::make('hourly_rate')->label('Tariffa oraria')->money('EUR'),
                        TextEntry::make('vat_rate')->label('IVA')->suffix('%'),
                    ]),

                Section::make('Totali')
                    ->columns(3)
                    ->components([
                        TextEntry::make('taxable_amount')
                            ->label('Imponibile')
                            ->state(fn (Quote $record): float => $record->taxableAmount())
                            ->money('EUR'),
                        TextEntry::make('vat_amount')
                            ->label('IVA')
                            ->state(fn (Quote $record): float => $record->vatAmount())
                            ->money('EUR'),
                        TextEntry::make('total')
                            ->label('Totale')
                            ->state(fn (Quote $record): float => $record->total())
                            ->money('EUR')
                            ->weight('bold'),
                    ]),

                Section::make('Invio')
                    ->columns(2)
                    ->visible(fn (Quote $record): bool => $record->sent_at !== null)
                    ->components([
                        TextEntry::make('sent_at')->label('Inviato il')->dateTime()->placeholder('—'),
                        TextEntry::make('reminders_sent')
                            ->label('Solleciti inviati')
                            ->state(fn (Quote $record): string => $record->reminders_sent . ' / 2'),
                    ]),

                Section::make('Approvazione')
                    ->columns(2)
                    ->visible(fn (Quote $record): bool => in_array($record->status, [Quote::STATUS_ACCEPTED, Quote::STATUS_INVOICED], true))
                    ->components([
                        TextEntry::make('acceptedBy.name')->label('Accettato da')->placeholder('—'),
                        TextEntry::make('accepted_at')->label('Accettato il')->dateTime()->placeholder('—'),
                        TextEntry::make('invoice.number')
                            ->label('Fattura generata')
                            ->placeholder('—'),
                    ]),

                Section::make('Note')
                    ->components([
                        TextEntry::make('notes')->label('')->placeholder('—'),
                    ]),
            ]);
    }
}
