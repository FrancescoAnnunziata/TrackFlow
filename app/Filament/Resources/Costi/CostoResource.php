<?php

namespace App\Filament\Resources\Costi;

use App\Filament\Resources\Costi\Pages\CreateCosto;
use App\Filament\Resources\Costi\Pages\EditCosto;
use App\Filament\Resources\Costi\Pages\ListCosti;
use App\Filament\Resources\Costi\Schemas\CostoForm;
use App\Filament\Resources\Costi\Tables\CostiTable;
use App\Models\Costo;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CostoResource extends Resource
{
    protected static ?string $model = Costo::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|\UnitEnum|null $navigationGroup = 'Controllo Finanziario';

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'description';

    protected static ?string $modelLabel = 'Costo';

    protected static ?string $pluralModelLabel = 'Costi';

    public static function form(Schema $schema): Schema
    {
        return CostoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CostiTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->canManageFinance();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCosti::route('/'),
            'create' => CreateCosto::route('/create'),
            'edit' => EditCosto::route('/{record}/edit'),
        ];
    }
}
