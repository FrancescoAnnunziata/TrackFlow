<?php

namespace App\Filament\Resources\Emittenti;

use App\Filament\Resources\Emittenti\Pages\EditEmittente;
use App\Filament\Resources\Emittenti\Pages\ListEmittenti;
use App\Filament\Resources\Emittenti\Schemas\EmittenteForm;
use App\Filament\Resources\Emittenti\Tables\EmittentiTable;
use App\Models\Emittente;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Intestazioni di chi emette i documenti (G8 Labs e la P.IVA personale). Si
 * modificano soltanto: crearne o cancellarne una tocca dashboard, soglia e
 * riconciliazione, quindi resta una scelta da fare nel codice.
 */
class EmittenteResource extends Resource
{
    protected static ?string $model = Emittente::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|\UnitEnum|null $navigationGroup = 'Impostazioni';

    protected static ?string $recordTitleAttribute = 'nome';

    protected static ?string $modelLabel = 'Emittente';

    protected static ?string $pluralModelLabel = 'Emittenti';

    protected static ?string $slug = 'emittenti';

    public static function form(Schema $schema): Schema
    {
        return EmittenteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmittentiTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->isAdmin();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmittenti::route('/'),
            'edit' => EditEmittente::route('/{record}/edit'),
        ];
    }
}
