<?php

namespace App\Filament\Resources\Devices\Schemas;

use App\Models\Device;
use App\Services\Security\EndpointHistory;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

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
                        TextEntry::make('hostname')->label('Hostname')->placeholder('—'),
                        TextEntry::make('status')->label('Stato')->badge(),
                        TextEntry::make('location')->label('Ubicazione')->placeholder('—'),
                        TextEntry::make('department')->label('Reparto')->placeholder('—'),
                        TextEntry::make('lifecycle_stage')->label('Stato ciclo di vita')->placeholder('—'),
                    ]),

                Section::make('Assegnazione')
                    ->columns(3)
                    ->components([
                        TextEntry::make('assignedUser.full_name')->label('Assegnatario corrente')->placeholder('Non assegnato'),
                        TextEntry::make('inventory_assignee')
                            ->label('Assegnatario da censimento')
                            ->placeholder('—')
                            ->hintIcon(Heroicon::OutlinedInformationCircle)
                            ->hintIconTooltip('Testo libero compilato nello script di censimento: viene agganciato a un utente solo se il nome corrisponde.'),
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

                Section::make('Sicurezza endpoint')
                    ->description('Stato dei campi critici sull\'ultima rilevazione e da quante rilevazioni consecutive dura.')
                    ->components([
                        View::make('filament.devices.security-summary')
                            ->viewData(fn (Device $record): array => self::securitySummaryData($record)),
                    ]),

                Section::make('Note')
                    ->components([
                        TextEntry::make('notes')->label('')->placeholder('—')->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @return array{summary: array<string, mixed>, rilevazioni: int}
     */
    private static function securitySummaryData(Device $record): array
    {
        return [
            'summary' => app(EndpointHistory::class)->summary($record),
            'rilevazioni' => $record->securityChecks()->count(),
        ];
    }
}
