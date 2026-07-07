<?php

namespace App\Filament\Resources\SecurityFindings;

use App\Filament\Resources\SecurityFindings\Pages\CreateSecurityFinding;
use App\Filament\Resources\SecurityFindings\Pages\EditSecurityFinding;
use App\Filament\Resources\SecurityFindings\Pages\ListSecurityFindings;
use App\Filament\Resources\SecurityFindings\Schemas\SecurityFindingForm;
use App\Filament\Resources\SecurityFindings\Tables\SecurityFindingsTable;
use App\Models\SecurityFinding;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SecurityFindingResource extends Resource
{
    protected static ?string $model = SecurityFinding::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|\UnitEnum|null $navigationGroup = 'Asset Management';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $modelLabel = 'Criticità';

    protected static ?string $pluralModelLabel = 'Criticità cybersecurity';

    public static function form(Schema $schema): Schema
    {
        return SecurityFindingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SecurityFindingsTable::configure($table);
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

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', 'open')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSecurityFindings::route('/'),
            'create' => CreateSecurityFinding::route('/create'),
            'edit' => EditSecurityFinding::route('/{record}/edit'),
        ];
    }
}
