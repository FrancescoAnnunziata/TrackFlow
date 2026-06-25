<?php

namespace App\Filament\Resources\DeviceSecurityChecks;

use App\Filament\Resources\DeviceSecurityChecks\Pages\CreateDeviceSecurityCheck;
use App\Filament\Resources\DeviceSecurityChecks\Pages\EditDeviceSecurityCheck;
use App\Filament\Resources\DeviceSecurityChecks\Pages\ListDeviceSecurityChecks;
use App\Filament\Resources\DeviceSecurityChecks\Schemas\DeviceSecurityCheckForm;
use App\Filament\Resources\DeviceSecurityChecks\Tables\DeviceSecurityChecksTable;
use App\Models\DeviceSecurityCheck;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DeviceSecurityCheckResource extends Resource
{
    protected static ?string $model = DeviceSecurityCheck::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Asset Management';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Security check';

    protected static ?string $pluralModelLabel = 'Security check';

    public static function form(Schema $schema): Schema
    {
        return DeviceSecurityCheckForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DeviceSecurityChecksTable::configure($table);
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
            'index' => ListDeviceSecurityChecks::route('/'),
            'create' => CreateDeviceSecurityCheck::route('/create'),
            'edit' => EditDeviceSecurityCheck::route('/{record}/edit'),
        ];
    }
}
