<?php

namespace App\Filament\Resources\Quotes;

use App\Filament\Resources\Quotes\Pages\CreateQuote;
use App\Filament\Resources\Quotes\Pages\EditQuote;
use App\Filament\Resources\Quotes\Pages\ListQuotes;
use App\Filament\Resources\Quotes\Pages\ViewQuote;
use App\Filament\Resources\Quotes\Schemas\QuoteForm;
use App\Filament\Resources\Quotes\Schemas\QuoteInfolist;
use App\Filament\Resources\Quotes\Tables\QuotesTable;
use App\Models\Quote;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class QuoteResource extends Resource
{
    protected static ?string $model = Quote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $modelLabel = 'Preventivo';

    protected static ?string $pluralModelLabel = 'Preventivi';

    public static function form(Schema $schema): Schema
    {
        return QuoteForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return QuoteInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return QuotesTable::configure($table);
    }

    /**
     * Admin gestisce tutto; il cliente vede (e approva) solo i propri.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user->isClient()) {
            return $query->whereIn('client_id', $user->allClientIds());
        }

        return $query;
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user->isAdmin() || $user->isClient();
    }

    public static function canCreate(): bool
    {
        return auth()->user()->isAdmin();
    }

    public static function canEdit($record): bool
    {
        return auth()->user()->isAdmin();
    }

    public static function canDelete($record): bool
    {
        return auth()->user()->isAdmin();
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
            'index' => ListQuotes::route('/'),
            'create' => CreateQuote::route('/create'),
            'view' => ViewQuote::route('/{record}'),
            'edit' => EditQuote::route('/{record}/edit'),
        ];
    }
}
