<?php

namespace App\Filament\Resources\Quotes\Schemas;

use App\Models\Quote;
use Filament\Infolists\Components\ImageEntry;
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
                        TextEntry::make('issuer_key')
                            ->label('Intestazione')
                            ->state(fn (Quote $record): string => $record->emittente()->nome()),
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
                    ->columns(3)
                    ->visible(fn (Quote $record): bool => $record->sent_at !== null)
                    ->components([
                        TextEntry::make('sent_at')->label('Inviato il')->dateTime()->placeholder('—'),
                        TextEntry::make('reminders_sent')
                            ->label('Solleciti inviati')
                            ->state(fn (Quote $record): string => $record->reminders_sent.' / 2'),
                        TextEntry::make('document_viewed_at')
                            ->label('Documento aperto il')
                            ->dateTime()
                            ->placeholder('mai aperto'),
                    ]),

                Section::make('Firma di accettazione')
                    ->columns(2)
                    ->visible(fn (Quote $record): bool => $record->isSigned())
                    ->components([
                        TextEntry::make('signer_name')
                            ->label('Firmato da')
                            ->formatStateUsing(fn (?string $state, Quote $record): string => trim(
                                (string) $state.($record->signer_role ? " — {$record->signer_role}" : '')
                            ))
                            ->placeholder('—'),
                        TextEntry::make('accepted_at')->label('Firmato il')->dateTime()->placeholder('—'),
                        // La firma è un PNG su disco privato: la mostriamo inline
                        // come data URI, senza esporre il file.
                        ImageEntry::make('signature_path')
                            ->label('Firma')
                            ->state(fn (Quote $record): ?string => $record->signatureDataUri())
                            ->extraImgAttributes(['style' => 'max-height: 90px; background: #fff;'])
                            ->columnSpanFull(),
                        TextEntry::make('signature_ip')
                            ->label('Tracciatura')
                            ->state(fn (Quote $record): string => trim(
                                'IP '.($record->signature_ip ?: 'n/d')
                                .($record->signature_user_agent ? ' — '.$record->signature_user_agent : '')
                            ))
                            ->columnSpanFull()
                            ->size('xs')
                            ->color('gray'),
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

                Section::make('Rifiuto')
                    ->columns(2)
                    ->visible(fn (Quote $record): bool => $record->status === Quote::STATUS_REJECTED)
                    ->components([
                        TextEntry::make('rejected_at')->label('Rifiutato il')->dateTime()->placeholder('—'),
                        TextEntry::make('rejection_reason')
                            ->label('Motivo')
                            ->placeholder('nessun motivo indicato')
                            ->columnSpanFull(),
                    ]),

                Section::make('Note')
                    ->components([
                        TextEntry::make('notes')->label('')->placeholder('—'),
                    ]),
            ]);
    }
}
