<?php

namespace App\Filament\Resources\DeviceSecurityChecks\Schemas;

use App\Enums\SecurityOutcome;
use App\Enums\SecurityRiskLevel;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DeviceSecurityCheckForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dispositivo')
                    ->columns(2)
                    ->components([
                        Select::make('device_id')
                            ->label('Dispositivo')
                            ->relationship('device', 'asset_code')
                            ->searchable()
                            ->preload()
                            ->required(),
                        DateTimePicker::make('checked_at')
                            ->label('Data verifica')
                            ->default(now())
                            ->required(),
                    ]),

                Section::make('Controlli di sicurezza')
                    ->columns(2)
                    ->components([
                        Toggle::make('os_updated')->label('Sistema operativo aggiornato'),
                        Toggle::make('antivirus_active')->label('Antivirus attivo'),
                        Toggle::make('antivirus_updated')->label('Antivirus aggiornato'),
                        Toggle::make('firewall_active')->label('Firewall attivo'),
                        Toggle::make('disk_encryption_active')->label('Cifratura disco attiva'),
                        Toggle::make('screen_lock_active')->label('Blocco schermo attivo'),
                        Toggle::make('admin_user_disabled')->label('Utente admin locale disabilitato'),
                        Toggle::make('mfa_enabled')->label('MFA abilitata'),
                        Toggle::make('backup_configured')->label('Backup configurato'),
                        Toggle::make('usb_policy_ok')->label('Policy USB conforme'),
                        Toggle::make('password_policy_ok')->label('Policy password conforme'),
                    ]),

                Section::make('Esito')
                    ->columns(2)
                    ->components([
                        Select::make('risk_level')
                            ->label('Livello di rischio')
                            ->options(SecurityRiskLevel::class)
                            ->default(SecurityRiskLevel::Low)
                            ->required(),
                        Select::make('outcome')
                            ->label('Esito')
                            ->options(SecurityOutcome::class)
                            ->default(SecurityOutcome::Compliant)
                            ->required(),
                        DatePicker::make('next_check_at')
                            ->label('Prossima verifica'),
                        Textarea::make('notes')
                            ->label('Note')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
