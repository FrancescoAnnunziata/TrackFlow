<?php

namespace App\Filament\Resources\TravelRates;

use App\Filament\Resources\TravelRates\Pages\CreateTravelRate;
use App\Filament\Resources\TravelRates\Pages\EditTravelRate;
use App\Filament\Resources\TravelRates\Pages\ListTravelRates;
use App\Filament\Resources\TravelRates\Schemas\TravelRateForm;
use App\Filament\Resources\TravelRates\Tables\TravelRatesTable;
use App\Models\TravelRate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class TravelRateResource extends Resource
{
    protected static ?string $model = TravelRate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Controllo Finanziario';

    protected static ?string $recordTitleAttribute = 'tipo';

    protected static ?string $modelLabel = 'Tariffa trasferta';

    protected static ?string $pluralModelLabel = 'Tabella trasferte';

    public static function canViewAny(): bool
    {
        return ! auth()->user()->isClient();
    }

    /**
     * Ogni utente gestisce la propria tabella di mappatura trasferte; l'admin
     * le vede tutte.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! auth()->user()->isAdmin()) {
            $query->where('user_id', auth()->id());
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return TravelRateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TravelRatesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTravelRates::route('/'),
            'create' => CreateTravelRate::route('/create'),
            'edit' => EditTravelRate::route('/{record}/edit'),
        ];
    }
}
