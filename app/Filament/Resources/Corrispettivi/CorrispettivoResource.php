<?php

namespace App\Filament\Resources\Corrispettivi;

use App\Filament\Resources\Corrispettivi\Pages\CreateCorrispettivo;
use App\Filament\Resources\Corrispettivi\Pages\EditCorrispettivo;
use App\Filament\Resources\Corrispettivi\Pages\ListCorrispettivi;
use App\Filament\Resources\Corrispettivi\Schemas\CorrispettivoForm;
use App\Filament\Resources\Corrispettivi\Tables\CorrispettiviTable;
use App\Models\Corrispettivo;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CorrispettivoResource extends Resource
{
    protected static ?string $model = Corrispettivo::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|\UnitEnum|null $navigationGroup = 'Controllo Finanziario';

    protected static ?int $navigationSort = 7;

    protected static ?string $recordTitleAttribute = 'date';

    protected static ?string $modelLabel = 'Corrispettivo';

    protected static ?string $pluralModelLabel = 'Corrispettivi e-commerce';

    public static function form(Schema $schema): Schema
    {
        return CorrispettivoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CorrispettiviTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->isAdmin();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCorrispettivi::route('/'),
            'create' => CreateCorrispettivo::route('/create'),
            'edit' => EditCorrispettivo::route('/{record}/edit'),
        ];
    }
}
