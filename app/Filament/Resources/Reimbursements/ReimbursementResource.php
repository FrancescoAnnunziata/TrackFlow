<?php

namespace App\Filament\Resources\Reimbursements;

use App\Filament\Resources\Reimbursements\Pages\CreateReimbursement;
use App\Filament\Resources\Reimbursements\Pages\EditReimbursement;
use App\Filament\Resources\Reimbursements\Pages\ListReimbursements;
use App\Filament\Resources\Reimbursements\Pages\ViewReimbursement;
use App\Filament\Resources\Reimbursements\Schemas\ReimbursementForm;
use App\Filament\Resources\Reimbursements\Schemas\ReimbursementInfolist;
use App\Filament\Resources\Reimbursements\Tables\ReimbursementsTable;
use App\Models\Reimbursement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ReimbursementResource extends Resource
{
    protected static ?string $model = Reimbursement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Controllo Finanziario';

    protected static ?string $recordTitleAttribute = 'date';

    protected static ?string $modelLabel = 'Rimborso spese';

    protected static ?string $pluralModelLabel = 'Rimborsi spese';

    public static function form(Schema $schema): Schema
    {
        return ReimbursementForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ReimbursementInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReimbursementsTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return ! auth()->user()->isClient();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Ogni utente vede i propri rimborsi; l'admin li vede tutti.
        if (! auth()->user()->isAdmin()) {
            $query->where('user_id', auth()->id());
        }

        return $query;
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
            'index' => ListReimbursements::route('/'),
            'create' => CreateReimbursement::route('/create'),
            'view' => ViewReimbursement::route('/{record}'),
            'edit' => EditReimbursement::route('/{record}/edit'),
        ];
    }
}
