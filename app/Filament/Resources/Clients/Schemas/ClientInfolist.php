<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Models\Client;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ClientInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Anagrafica')
                    ->columns(2)
                    ->components([
                        TextEntry::make('name')->label('Ragione sociale / Nome'),
                        TextEntry::make('entity_type')
                            ->label('Tipo soggetto')
                            ->formatStateUsing(fn (?string $state): string => $state === 'person' ? 'Privato / Persona fisica' : 'Azienda'),
                        ImageEntry::make('logo')
                            ->label('Logo')
                            ->disk('public')
                            ->circular()
                            ->visible(fn (Client $record): bool => filled($record->logo)),
                        TextEntry::make('asset_prefix')->label('Prefisso asset')->placeholder('—'),
                    ]),

                Section::make('Dati fiscali')
                    ->columns(2)
                    ->components([
                        TextEntry::make('vat_number')->label('Partita IVA')->placeholder('—'),
                        TextEntry::make('tax_code')->label('Codice Fiscale')->placeholder('—'),
                        TextEntry::make('ei_code')->label('Codice destinatario (SDI)')->placeholder('—'),
                        TextEntry::make('certified_email')->label('PEC')->placeholder('—'),
                    ]),

                Section::make('Fatturazione')
                    ->columns(2)
                    ->components([
                        TextEntry::make('invoicing_provider')
                            ->label('Provider')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => match ($state) {
                                'fatture_in_cloud' => 'Fatture in Cloud',
                                'fiscozen' => 'Fiscozen',
                                default => 'Altro / esterno',
                            })
                            ->color(fn (?string $state): string => $state === 'fatture_in_cloud' ? 'success' : 'gray'),
                        TextEntry::make('billing_model')
                            ->label('Modello')
                            ->formatStateUsing(fn (?string $state): string => $state === Client::MODEL_FORFAIT ? 'Forfait' : 'A ore'),
                        TextEntry::make('billing_period_months')
                            ->label('Periodicità')
                            ->formatStateUsing(fn ($state): string => match ((int) $state) {
                                1 => 'Mensile',
                                3 => 'Trimestrale',
                                6 => 'Semestrale',
                                12 => 'Annuale',
                                default => $state.' mesi',
                            }),
                        TextEntry::make('billing_timing')
                            ->label('Timing')
                            ->formatStateUsing(fn (?string $state): string => $state === Client::TIMING_ADVANCE ? 'Anticipato' : 'Posticipato'),
                        TextEntry::make('forfait_amount')
                            ->label('Forfait mensile')
                            ->money('EUR')
                            ->placeholder('—')
                            ->visible(fn (Client $record): bool => $record->billing_model === Client::MODEL_FORFAIT),
                        TextEntry::make('default_hourly_rate')
                            ->label('Tariffa oraria default')
                            ->money('EUR')
                            ->placeholder('—')
                            ->visible(fn (Client $record): bool => $record->billing_model === Client::MODEL_HOURLY),
                        TextEntry::make('minimum_hours_per_month')
                            ->label('Minimo ore/mese')
                            ->placeholder('—')
                            ->visible(fn (Client $record): bool => $record->billing_model === Client::MODEL_HOURLY),
                        TextEntry::make('monthly_extra_amount')->label('Extra fisso/mese')->money('EUR')->placeholder('—'),
                        TextEntry::make('vat_rate')->label('IVA')->suffix('%'),
                    ]),

                Section::make('Tariffe per utente')
                    ->visible(fn (Client $record): bool => $record->userRates->isNotEmpty())
                    ->components([
                        RepeatableEntry::make('userRates')
                            ->label('')
                            ->columns(2)
                            ->schema([
                                TextEntry::make('user.name')->label('Utente'),
                                TextEntry::make('hourly_rate')->label('Tariffa (€/h)')->money('EUR'),
                            ]),
                    ]),

                Section::make('Indirizzo')
                    ->columns(2)
                    ->components([
                        TextEntry::make('address_street')->label('Indirizzo')->placeholder('—'),
                        TextEntry::make('address_postal_code')->label('CAP')->placeholder('—'),
                        TextEntry::make('address_city')->label('Città')->placeholder('—'),
                        TextEntry::make('address_province')->label('Provincia')->placeholder('—'),
                    ]),

                Section::make('Contatti')
                    ->components([
                        TextEntry::make('email')->label('Email')->placeholder('—')->columnSpanFull(),
                    ]),

                Section::make('Note')
                    ->components([
                        TextEntry::make('notes')->label('')->placeholder('—')->columnSpanFull(),
                    ]),
            ]);
    }
}
