<?php

namespace App\Filament\Resources\Devices\RelationManagers;

use App\Enums\SecurityOutcome;
use App\Enums\SecurityRiskLevel;
use App\Filament\Resources\DeviceSecurityChecks\DeviceSecurityCheckResource;
use App\Filament\Resources\DeviceSecurityChecks\Schemas\DeviceSecurityCheckInfolist;
use App\Models\DeviceSecurityCheck;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SecurityChecksRelationManager extends RelationManager
{
    protected static string $relationship = 'securityChecks';

    protected static ?string $title = 'Security check';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DateTimePicker::make('checked_at')
                    ->label('Data verifica')
                    ->default(now())
                    ->required(),
                Section::make('Controlli')
                    ->columns(2)
                    ->components([
                        Toggle::make('os_updated')->label('SO aggiornato'),
                        Toggle::make('antivirus_active')->label('Antivirus attivo'),
                        Toggle::make('antivirus_updated')->label('Antivirus aggiornato'),
                        Toggle::make('firewall_active')->label('Firewall attivo'),
                        Toggle::make('disk_encryption_active')->label('Cifratura disco'),
                        Toggle::make('screen_lock_active')->label('Blocco schermo'),
                        Toggle::make('admin_user_disabled')->label('Admin locale disabilitato'),
                        Toggle::make('mfa_enabled')->label('MFA abilitata'),
                        Toggle::make('backup_configured')->label('Backup configurato'),
                        Toggle::make('usb_policy_ok')->label('Policy USB ok'),
                        Toggle::make('password_policy_ok')->label('Policy password ok'),
                    ]),
                Section::make('Esito')
                    ->columns(2)
                    ->components([
                        Select::make('risk_level')
                            ->label('Rischio')
                            ->options(SecurityRiskLevel::class)
                            ->default(SecurityRiskLevel::Low)
                            ->required(),
                        Select::make('outcome')
                            ->label('Esito')
                            ->options(SecurityOutcome::class)
                            ->default(SecurityOutcome::Compliant)
                            ->required(),
                        DatePicker::make('next_check_at')->label('Prossima verifica'),
                        Textarea::make('notes')->label('Note')->columnSpanFull(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('checked_at', 'desc')
            ->columns([
                TextColumn::make('checked_at')->label('Verificato il')->dateTime('d/m/Y H:i'),
                TextColumn::make('detected_by')
                    ->label('Da')
                    ->placeholder(fn (DeviceSecurityCheck $record): string => $record->checkedBy?->name ?? '—'),
                TextColumn::make('criticita')
                    ->label('Criticità')
                    ->badge()
                    ->state(fn (DeviceSecurityCheck $record): int => count($record->criticalIssues()))
                    ->color(fn (int $state): string => $state === 0 ? 'success' : 'danger')
                    ->tooltip(fn (DeviceSecurityCheck $record): ?string => collect($record->criticalIssues())
                        ->pluck('label')
                        ->implode(', ') ?: null),
                TextColumn::make('os_support')
                    ->label('Supporto SO')
                    ->badge()
                    ->state(fn (DeviceSecurityCheck $record): string => DeviceSecurityCheckInfolist::criticalBadgeText('os_support', $record, 'os_support'))
                    ->color(fn (DeviceSecurityCheck $record): string => DeviceSecurityCheckInfolist::stateColor($record->criticalState('os_support'))),
                TextColumn::make('bitlocker_protection')
                    ->label('BitLocker')
                    ->badge()
                    ->state(fn (DeviceSecurityCheck $record): string => DeviceSecurityCheckInfolist::criticalBadgeText('bitlocker', $record, 'bitlocker_protection'))
                    ->color(fn (DeviceSecurityCheck $record): string => DeviceSecurityCheckInfolist::stateColor($record->criticalState('bitlocker'))),
                TextColumn::make('risk_level')->label('Rischio')->badge(),
                TextColumn::make('outcome')->label('Esito')->badge(),
                TextColumn::make('next_check_at')->label('Prossima')->date('d/m/Y')->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => ! auth()->user()->isClient())
                    ->after(fn (DeviceSecurityCheck $record) => $record->generateFindingsForFailures()),
            ])
            ->recordActions([
                Action::make('dettaglio')
                    ->label('Dettaglio')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (DeviceSecurityCheck $record): string => DeviceSecurityCheckResource::getUrl('view', ['record' => $record])),
                EditAction::make()
                    ->visible(fn (): bool => ! auth()->user()->isClient()),
            ]);
    }
}
