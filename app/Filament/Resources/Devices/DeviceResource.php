<?php

namespace App\Filament\Resources\Devices;

use App\Filament\Resources\Devices\Pages\CreateDevice;
use App\Filament\Resources\Devices\Pages\EditDevice;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Filament\Resources\Devices\Pages\ViewDevice;
use App\Filament\Resources\Devices\RelationManagers\AssignmentsRelationManager;
use App\Filament\Resources\Devices\RelationManagers\FindingsRelationManager;
use App\Filament\Resources\Devices\RelationManagers\MaintenancesRelationManager;
use App\Filament\Resources\Devices\RelationManagers\SecurityChecksRelationManager;
use App\Filament\Resources\Devices\RelationManagers\TicketsRelationManager;
use App\Filament\Resources\Devices\Schemas\DeviceForm;
use App\Filament\Resources\Devices\Schemas\DeviceInfolist;
use App\Filament\Resources\Devices\Tables\DevicesTable;
use App\Models\Device;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\UnitEnum|null $navigationGroup = 'Asset Management';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Dispositivo';

    protected static ?string $pluralModelLabel = 'Dispositivi';

    public static function form(Schema $schema): Schema
    {
        return DeviceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DeviceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DevicesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && $user->isClient()) {
            return $query->whereIn('client_id', $user->allClientIds());
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [
            AssignmentsRelationManager::class,
            MaintenancesRelationManager::class,
            SecurityChecksRelationManager::class,
            TicketsRelationManager::class,
            FindingsRelationManager::class,
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['asset_code', 'barcode', 'serial_number', 'name', 'model'];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDevices::route('/'),
            'create' => CreateDevice::route('/create'),
            'view' => ViewDevice::route('/{record}'),
            'edit' => EditDevice::route('/{record}/edit'),
        ];
    }
}
