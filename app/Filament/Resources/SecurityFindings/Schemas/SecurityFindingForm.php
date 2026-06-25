<?php

namespace App\Filament\Resources\SecurityFindings\Schemas;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SecurityFindingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Criticità')
                    ->columns(2)
                    ->components([
                        Select::make('device_id')
                            ->label('Dispositivo')
                            ->relationship('device', 'asset_code')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('device_security_check_id')
                            ->label('Security check di origine')
                            ->relationship('securityCheck', 'id')
                            ->searchable()
                            ->preload(),
                        TextInput::make('title')
                            ->label('Titolo')
                            ->required()
                            ->columnSpanFull(),
                        Textarea::make('description')
                            ->label('Descrizione')
                            ->columnSpanFull(),
                    ]),

                Section::make('Remediation')
                    ->columns(2)
                    ->components([
                        Select::make('severity')
                            ->label('Severità')
                            ->options(FindingSeverity::class)
                            ->default(FindingSeverity::Medium)
                            ->required(),
                        Select::make('status')
                            ->label('Stato')
                            ->options(FindingStatus::class)
                            ->default(FindingStatus::Open)
                            ->required(),
                        DatePicker::make('due_date')
                            ->label('Scadenza'),
                        Select::make('resolved_by_user_id')
                            ->label('Risolto da')
                            ->relationship('resolvedBy', 'name')
                            ->searchable()
                            ->preload(),
                    ]),
            ]);
    }
}
