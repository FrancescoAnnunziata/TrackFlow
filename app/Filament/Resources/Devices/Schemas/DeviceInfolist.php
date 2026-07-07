<?php

namespace App\Filament\Resources\Devices\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DeviceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dati principali')
                    ->columns(3)
                    ->components([
                        TextEntry::make('asset_code')->label('Codice asset')->copyable(),
                        TextEntry::make('barcode')->label('Barcode'),
                        TextEntry::make('name')->label('Nome'),
                        TextEntry::make('client.name')->label('Cliente'),
                        TextEntry::make('category')->label('Categoria')->badge(),
                        TextEntry::make('type')->label('Tipo')->placeholder('—'),
                        TextEntry::make('manufacturer')->label('Produttore')->placeholder('—'),
                        TextEntry::make('model')->label('Modello')->placeholder('—'),
                        TextEntry::make('serial_number')->label('Numero di serie')->placeholder('—'),
                        TextEntry::make('status')->label('Stato')->badge(),
                        TextEntry::make('location')->label('Ubicazione')->placeholder('—'),
                    ]),

                Section::make('Assegnazione')
                    ->columns(3)
                    ->components([
                        TextEntry::make('assignedUser.full_name')->label('Assegnatario corrente')->placeholder('Non assegnato'),
                    ]),

                Section::make('Acquisto e garanzia')
                    ->columns(3)
                    ->components([
                        TextEntry::make('purchase_date')->label('Data acquisto')->date()->placeholder('—'),
                        TextEntry::make('purchase_price')->label('Prezzo')->money('EUR')->placeholder('—'),
                        TextEntry::make('supplier')->label('Fornitore')->placeholder('—'),
                        TextEntry::make('invoice_number')->label('Numero fattura')->placeholder('—'),
                        TextEntry::make('warranty_until')->label('Garanzia fino al')->date()->placeholder('—'),
                        TextEntry::make('next_maintenance_at')->label('Prossima manutenzione')->date()->placeholder('—'),
                    ]),

                Section::make('Stato sicurezza')
                    ->columns(3)
                    ->components([
                        TextEntry::make('latestSecurityCheck.outcome')->label('Ultimo esito')->badge()->placeholder('Mai verificato'),
                        TextEntry::make('latestSecurityCheck.risk_level')->label('Rischio')->badge()->placeholder('—'),
                        TextEntry::make('latestSecurityCheck.checked_at')->label('Verificato il')->dateTime()->placeholder('—'),
                        TextEntry::make('latestSecurityCheck.next_check_at')->label('Prossima verifica')->date()->placeholder('—'),
                    ]),

                Section::make('Note')
                    ->components([
                        TextEntry::make('notes')->label('')->placeholder('—')->columnSpanFull(),
                    ]),
            ]);
    }
}
