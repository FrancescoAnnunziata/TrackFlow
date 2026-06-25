<?php

namespace App\Filament\Resources\DeviceMaintenances\Schemas;

use App\Enums\MaintenanceType;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class DeviceMaintenanceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('device_id')
                    ->label('Dispositivo')
                    ->relationship('device', 'asset_code')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('performed_by_user_id')
                    ->label('Eseguita da')
                    ->relationship('performedBy', 'name')
                    ->searchable()
                    ->preload()
                    ->default(fn () => auth()->id()),
                DatePicker::make('maintenance_date')
                    ->label('Data')
                    ->default(now())
                    ->required(),
                Select::make('type')
                    ->label('Tipo')
                    ->options(MaintenanceType::class)
                    ->default(MaintenanceType::Ordinary)
                    ->required(),
                TextInput::make('cost')
                    ->label('Costo')
                    ->numeric()
                    ->prefix('EUR')
                    ->step(0.01),
                TextInput::make('supplier')
                    ->label('Fornitore'),
                DatePicker::make('next_maintenance_at')
                    ->label('Prossima manutenzione'),
                Textarea::make('description')
                    ->label('Descrizione')
                    ->columnSpanFull(),
                Textarea::make('notes')
                    ->label('Note')
                    ->columnSpanFull(),
            ]);
    }
}
