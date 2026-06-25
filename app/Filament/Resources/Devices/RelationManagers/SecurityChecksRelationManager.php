<?php

namespace App\Filament\Resources\Devices\RelationManagers;

use App\Enums\SecurityOutcome;
use App\Enums\SecurityRiskLevel;
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
                TextColumn::make('checked_at')->label('Verificato il')->dateTime(),
                TextColumn::make('checkedBy.name')->label('Da')->placeholder('—'),
                TextColumn::make('risk_level')->label('Rischio')->badge(),
                TextColumn::make('outcome')->label('Esito')->badge(),
                TextColumn::make('next_check_at')->label('Prossima')->date()->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => ! auth()->user()->isClient())
                    ->after(fn (DeviceSecurityCheck $record) => $record->generateFindingsForFailures()),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => ! auth()->user()->isClient()),
            ]);
    }
}
