<?php

namespace App\Filament\Resources\DeviceSecurityChecks\Schemas;

use App\Models\DeviceSecurityCheck;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Dettaglio di una rilevazione del censimento endpoint.
 *
 * I sei campi critici sono in cima e colorati in base allo stato calcolato
 * dalla rilevazione stessa (rosso = rischio, grigio = non rilevato), cosi' la
 * lettura parte da cosa non va senza dover scorrere i sessanta campi.
 */
class DeviceSecurityCheckInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Rilevazione')
                    ->columns(4)
                    ->components([
                        TextEntry::make('device.asset_code')->label('Codice asset'),
                        TextEntry::make('hostname')->label('Hostname')->placeholder('—'),
                        TextEntry::make('checked_at')->label('Data rilevazione')->dateTime('d/m/Y H:i'),
                        TextEntry::make('detected_by')->label('Rilevato da')->placeholder('—'),
                        TextEntry::make('outcome')->label('Esito')->badge(),
                        TextEntry::make('risk_level')->label('Rischio')->badge(),
                        TextEntry::make('ran_as_admin')
                            ->label('Eseguito come admin')
                            ->badge()
                            ->formatStateUsing(fn (?bool $state): string => self::yesNo($state))
                            ->color(fn (?bool $state): string => $state === true ? 'success' : 'warning'),
                        TextEntry::make('source')
                            ->label('Origine')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state === DeviceSecurityCheck::SOURCE_INVENTORY
                                ? 'Censimento CSV'
                                : 'Verifica manuale')
                            ->color('gray'),
                    ]),

                Section::make('Campi critici')
                    ->description('Rosso: valore in stato di rischio. Grigio: non rilevato dallo script.')
                    ->columns(3)
                    ->visible(fn (DeviceSecurityCheck $record): bool => $record->isFromInventory())
                    ->components([
                        self::critical('os_support', 'os_support', 'Supporto sistema operativo'),
                        self::critical('bitlocker', 'bitlocker_protection', 'Protezione BitLocker'),
                        self::critical('laps', 'laps', 'LAPS'),
                        self::critical('admin_group', 'admin_group_members', 'Amministratori locali')
                            ->columnSpan(2),
                        self::critical('av_tamper', 'av_tamper_protection', 'Tamper Protection')
                            ->formatStateUsing(fn (?bool $state): string => self::yesNo($state)),
                        self::critical('backup_restore', 'backup_last_restore_test_at', 'Ultimo restore testato')
                            ->formatStateUsing(fn ($state): string => $state?->format('d/m/Y') ?? 'Mai testato'),
                    ]),

                Section::make('Sistema operativo e patch')
                    ->columns(4)
                    ->collapsible()
                    ->components([
                        TextEntry::make('os_name')->label('Sistema operativo')->placeholder('—'),
                        TextEntry::make('os_edition')->label('Edizione')->placeholder('—'),
                        TextEntry::make('os_version')->label('Versione')->placeholder('—'),
                        TextEntry::make('os_build')->label('Build')->placeholder('—'),
                        TextEntry::make('os_architecture')->label('Architettura')->placeholder('—'),
                        TextEntry::make('os_installed_at')->label('Installato il')->date('d/m/Y')->placeholder('—'),
                        TextEntry::make('last_reboot_at')->label('Ultimo riavvio')->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('days_since_reboot')->label('Giorni da riavvio')->placeholder('—'),
                        TextEntry::make('last_patch_at')->label('Ultima patch')->date('d/m/Y')->placeholder('—'),
                        TextEntry::make('last_patch_kb')->label('KB')->placeholder('—'),
                        TextEntry::make('days_since_last_patch')
                            ->label('Giorni da ultima patch')
                            ->badge()
                            ->color(fn (?int $state): string => self::daysColor($state, 'max_days_since_patch'))
                            ->placeholder('—'),
                        TextEntry::make('reboot_pending')
                            ->label('Riavvio pendente')
                            ->formatStateUsing(fn (?bool $state): string => self::yesNo($state)),
                    ]),

                Section::make('Antivirus')
                    ->columns(4)
                    ->collapsible()
                    ->components([
                        TextEntry::make('av_product')->label('Prodotto')->placeholder('—'),
                        TextEntry::make('av_third_party')->label('Terze parti')->placeholder('—'),
                        TextEntry::make('av_realtime')->label('Real time')->formatStateUsing(fn (?bool $s): string => self::yesNo($s)),
                        TextEntry::make('av_service_active')->label('Servizio attivo')->formatStateUsing(fn (?bool $s): string => self::yesNo($s)),
                        TextEntry::make('av_signatures_updated_at')->label('Firme aggiornate al')->date('d/m/Y')->placeholder('—'),
                        TextEntry::make('av_signatures_age_days')
                            ->label('Eta firme (giorni)')
                            ->badge()
                            ->color(fn (?int $state): string => self::daysColor($state, 'max_av_signature_age_days'))
                            ->placeholder('—'),
                        TextEntry::make('av_last_scan_at')->label('Ultima scansione')->date('d/m/Y')->placeholder('—'),
                    ]),

                Section::make('Cifratura e avvio')
                    ->columns(4)
                    ->collapsible()
                    ->components([
                        TextEntry::make('bitlocker_status')->label('Stato BitLocker')->placeholder('—'),
                        TextEntry::make('bitlocker_method')->label('Metodo')->placeholder('—'),
                        TextEntry::make('bitlocker_protectors')->label('Protettori')->placeholder('—'),
                        TextEntry::make('bitlocker_recovery_key_present')
                            ->label('Recovery key')
                            ->formatStateUsing(fn (?bool $s): string => self::yesNo($s)),
                        TextEntry::make('bitlocker_key_location')->label('Dove e custodita la chiave')->placeholder('Non indicato'),
                        TextEntry::make('tpm_present')->label('TPM presente')->formatStateUsing(fn (?bool $s): string => self::yesNo($s)),
                        TextEntry::make('tpm_ready')->label('TPM pronto')->formatStateUsing(fn (?bool $s): string => self::yesNo($s)),
                        TextEntry::make('secure_boot')->label('Secure Boot')->formatStateUsing(fn (?bool $s): string => self::yesNo($s)),
                    ]),

                Section::make('Account e accessi')
                    ->columns(3)
                    ->collapsible()
                    ->components([
                        TextEntry::make('builtin_admin_name')->label('Admin builtin')->placeholder('—'),
                        TextEntry::make('builtin_admin_status')->label('Stato admin builtin')->placeholder('—'),
                        TextEntry::make('builtin_admin_renamed')->label('Rinominato')->formatStateUsing(fn (?bool $s): string => self::yesNo($s)),
                        TextEntry::make('local_active_accounts')->label('Account locali attivi')->placeholder('—')->columnSpan(2),
                        TextEntry::make('logged_user')->label('Utente loggato')->placeholder('—'),
                        TextEntry::make('firewall_profiles')->label('Firewall')->placeholder('—')->columnSpan(2),
                        TextEntry::make('rdp_enabled')->label('RDP')->formatStateUsing(fn (?bool $s): string => self::yesNo($s)),
                        TextEntry::make('screen_lock_policy')->label('Blocco schermo')->placeholder('—')->columnSpan(2),
                        TextEntry::make('screen_timeout_ac_seconds')->label('Timeout video (s)')->placeholder('—'),
                    ]),

                Section::make('Gestione e software')
                    ->columns(4)
                    ->collapsible()
                    ->components([
                        TextEntry::make('azure_ad_joined')->label('Azure AD')->formatStateUsing(fn (?bool $s): string => self::yesNo($s)),
                        TextEntry::make('domain_membership')->label('In dominio')->placeholder('—'),
                        TextEntry::make('mdm_enrolled')->label('MDM')->formatStateUsing(fn (?bool $s): string => self::yesNo($s)),
                        TextEntry::make('mdm_url')->label('URL MDM')->placeholder('—'),
                        TextEntry::make('installed_software_count')->label('Software installati')->placeholder('—'),
                        TextEntry::make('onedrive_status')->label('OneDrive')->placeholder('—'),
                        TextEntry::make('remote_control_tools')
                            ->label('Strumenti di controllo remoto')
                            ->placeholder('Nessuno')
                            ->color(fn (?string $state): ?string => filled($state) ? 'warning' : null)
                            ->columnSpan(2),
                        TextEntry::make('critical_apps')->label('App critiche')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('ram_gb')->label('RAM (GB)')->placeholder('—'),
                        TextEntry::make('disks')->label('Dischi')->placeholder('—')->columnSpan(3),
                    ]),

                Section::make('Backup')
                    ->columns(3)
                    ->collapsible()
                    ->components([
                        TextEntry::make('backup_type')->label('Tipo')->placeholder('Non indicato'),
                        TextEntry::make('backup_last_ok_at')->label('Ultimo backup OK')->date('d/m/Y')->placeholder('—'),
                        TextEntry::make('backup_last_restore_test_at')->label('Ultimo restore testato')->date('d/m/Y')->placeholder('Mai'),
                    ]),

                Section::make('Note')
                    ->collapsible()
                    ->components([
                        TextEntry::make('notes')->label('')->placeholder('—')->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Voce di un campo critico: colore e icona vengono dallo stato calcolato
     * dalla rilevazione, il testo di aiuto riporta il motivo della segnalazione.
     */
    private static function critical(string $key, string $column, string $label): TextEntry
    {
        return TextEntry::make($column)
            ->label($label)
            ->badge()
            ->placeholder('Non rilevato')
            ->color(fn (DeviceSecurityCheck $record): string => self::stateColor($record->criticalState($key)))
            ->icon(fn (DeviceSecurityCheck $record): ?Heroicon => match ($record->criticalState($key)) {
                DeviceSecurityCheck::STATE_RISK => Heroicon::OutlinedExclamationTriangle,
                DeviceSecurityCheck::STATE_OK => Heroicon::OutlinedCheckCircle,
                default => Heroicon::OutlinedQuestionMarkCircle,
            })
            ->helperText(fn (DeviceSecurityCheck $record): ?string => $record->evaluateCritical($key)['state'] === DeviceSecurityCheck::STATE_RISK
                ? $record->evaluateCritical($key)['detail']
                : null);
    }

    public static function stateColor(string $state): string
    {
        return match ($state) {
            DeviceSecurityCheck::STATE_RISK => 'danger',
            DeviceSecurityCheck::STATE_OK => 'success',
            default => 'gray',
        };
    }

    private static function yesNo(?bool $state): string
    {
        return match ($state) {
            true => 'SI',
            false => 'NO',
            default => 'N/D',
        };
    }

    /** Colora un conteggio di giorni rispetto alla soglia di configurazione. */
    private static function daysColor(?int $days, string $threshold): string
    {
        if ($days === null) {
            return 'gray';
        }

        return $days <= (int) config("inventario_endpoint.thresholds.{$threshold}") ? 'success' : 'danger';
    }
}
