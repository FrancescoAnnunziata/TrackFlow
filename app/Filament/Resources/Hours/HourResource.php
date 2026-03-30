<?php

namespace App\Filament\Resources\Hours;

use App\Filament\Resources\Hours\Pages\CreateHour;
use App\Filament\Resources\Hours\Pages\EditHour;
use App\Filament\Resources\Hours\Pages\ListHours;
use App\Filament\Resources\Hours\Pages\ViewHour;
use App\Filament\Resources\Hours\Schemas\HourForm;
use App\Filament\Resources\Hours\Schemas\HourInfolist;
use App\Filament\Resources\Hours\Tables\HoursTable;
use App\Models\Hour;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class HourResource extends Resource
{
    protected static ?string $model = Hour::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'Hour';

    public static function form(Schema $schema): Schema
    {
        return HourForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return HourInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HoursTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_id', auth()->id());
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
            'index' => ListHours::route('/'),
            'create' => CreateHour::route('/create'),
            'view' => ViewHour::route('/{record}'),
            'edit' => EditHour::route('/{record}/edit'),
        ];
    }
}
