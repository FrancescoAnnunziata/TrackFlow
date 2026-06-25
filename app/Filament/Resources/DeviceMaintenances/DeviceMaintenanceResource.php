<?php

namespace App\Filament\Resources\DeviceMaintenances;

use App\Filament\Resources\DeviceMaintenances\Pages\CreateDeviceMaintenance;
use App\Filament\Resources\DeviceMaintenances\Pages\EditDeviceMaintenance;
use App\Filament\Resources\DeviceMaintenances\Pages\ListDeviceMaintenances;
use App\Filament\Resources\DeviceMaintenances\Schemas\DeviceMaintenanceForm;
use App\Filament\Resources\DeviceMaintenances\Tables\DeviceMaintenancesTable;
use App\Models\DeviceMaintenance;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DeviceMaintenanceResource extends Resource
{
    protected static ?string $model = DeviceMaintenance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|\UnitEnum|null $navigationGroup = 'Asset Management';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'Manutenzione';

    protected static ?string $pluralModelLabel = 'Manutenzioni';

    public static function form(Schema $schema): Schema
    {
        return DeviceMaintenanceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DeviceMaintenancesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && $user->isClient()) {
            return $query->where('client_id', $user->client_id);
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeviceMaintenances::route('/'),
            'create' => CreateDeviceMaintenance::route('/create'),
            'edit' => EditDeviceMaintenance::route('/{record}/edit'),
        ];
    }
}
