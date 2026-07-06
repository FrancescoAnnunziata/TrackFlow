<?php

namespace App\Filament\Resources\Costi\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CostoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('date')
                    ->label('Data')
                    ->default(now())
                    ->required(),
                TextInput::make('description')
                    ->label('Descrizione')
                    ->required(),
                TextInput::make('category')
                    ->label('Categoria')
                    ->datalist(['Commissioni bancarie', 'Tasse/F24', 'Bolli', 'Stipendi', 'Contributi', 'Altro']),
                TextInput::make('amount')
                    ->label('Importo')
                    ->numeric()
                    ->prefix('EUR')
                    ->step(0.01)
                    ->required(),
                TextInput::make('vat_amount')
                    ->label('IVA (se detraibile)')
                    ->numeric()
                    ->prefix('EUR')
                    ->step(0.01),
                Select::make('supplier_id')
                    ->label('Fornitore (opzionale)')
                    ->relationship(name: 'supplier', titleAttribute: 'name')
                    ->searchable()
                    ->preload(),
                Textarea::make('notes')
                    ->label('Note')
                    ->columnSpanFull(),
            ]);
    }
}
