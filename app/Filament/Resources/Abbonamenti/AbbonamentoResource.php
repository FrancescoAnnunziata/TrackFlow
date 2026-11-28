<?php

namespace App\Filament\Resources\Abbonamenti;

use App\Filament\Resources\Abbonamenti\Pages\CreateAbbonamento;
use App\Filament\Resources\Abbonamenti\Pages\EditAbbonamento;
use App\Filament\Resources\Abbonamenti\Pages\ListAbbonamenti;
use App\Filament\Resources\Abbonamenti\Schemas\AbbonamentoForm;
use App\Filament\Resources\Abbonamenti\Tables\AbbonamentiTable;
use App\Models\Abbonamento;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Abbonamenti fornitori trimestrali e annuali, con la data del prossimo
 * rinnovo. Una settimana prima parte la mail di promemoria
 * (abbonamenti:promemoria).
 */
class AbbonamentoResource extends Resource
{
    protected static ?string $model = Abbonamento::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|\UnitEnum|null $navigationGroup = 'Controllo Finanziario';

    protected static ?int $navigationSort = 7;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Abbonamento';

    protected static ?string $pluralModelLabel = 'Abbonamenti';

    protected static ?string $slug = 'abbonamenti';

    public static function form(Schema $schema): Schema
    {
        return AbbonamentoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AbbonamentiTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->canManageFinance();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAbbonamenti::route('/'),
            'create' => CreateAbbonamento::route('/create'),
            'edit' => EditAbbonamento::route('/{record}/edit'),
        ];
    }
}
