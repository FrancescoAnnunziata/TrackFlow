<?php

namespace App\Filament\Resources\PassiveInvoices;

use App\Filament\Resources\PassiveInvoices\Pages\CreatePassiveInvoice;
use App\Filament\Resources\PassiveInvoices\Pages\EditPassiveInvoice;
use App\Filament\Resources\PassiveInvoices\Pages\ListPassiveInvoices;
use App\Filament\Resources\PassiveInvoices\Pages\ViewPassiveInvoice;
use App\Filament\Resources\PassiveInvoices\Schemas\PassiveInvoiceForm;
use App\Filament\Resources\PassiveInvoices\Schemas\PassiveInvoiceInfolist;
use App\Filament\Resources\PassiveInvoices\Tables\PassiveInvoicesTable;
use App\Models\PassiveInvoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PassiveInvoiceResource extends Resource
{
    protected static ?string $model = PassiveInvoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|\UnitEnum|null $navigationGroup = 'Controllo Finanziario';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $modelLabel = 'Fattura passiva';

    protected static ?string $pluralModelLabel = 'Fatture passive';

    public static function form(Schema $schema): Schema
    {
        return PassiveInvoiceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PassiveInvoiceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PassiveInvoicesTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->canManageFinance();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPassiveInvoices::route('/'),
            'create' => CreatePassiveInvoice::route('/create'),
            'view' => ViewPassiveInvoice::route('/{record}'),
            'edit' => EditPassiveInvoice::route('/{record}/edit'),
        ];
    }
}
