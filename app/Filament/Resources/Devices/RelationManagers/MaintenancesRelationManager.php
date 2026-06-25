<?php

namespace App\Filament\Resources\Devices\RelationManagers;

use App\Enums\MaintenanceType;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MaintenancesRelationManager extends RelationManager
{
    protected static string $relationship = 'maintenances';

    protected static ?string $title = 'Manutenzioni';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                DatePicker::make('maintenance_date')
                    ->label('Data')
                    ->default(now())
                    ->required(),
                Select::make('type')
                    ->label('Tipo')
                    ->options(MaintenanceType::class)
                    ->default(MaintenanceType::Ordinary)
                    ->required(),
                Select::make('performed_by_user_id')
                    ->label('Eseguita da')
                    ->relationship('performedBy', 'name')
                    ->searchable()
                    ->preload()
                    ->default(fn () => auth()->id()),
                TextInput::make('cost')
                    ->label('Costo')
                    ->numeric()
                    ->prefix('EUR')
                    ->step(0.01),
                TextInput::make('supplier')
                    ->label('Fornitore'),
                DatePicker::make('next_maintenance_at')
                    ->label('Prossima manutenzione'),
                Textarea::make('description')
                    ->label('Descrizione')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('maintenance_date', 'desc')
            ->columns([
                TextColumn::make('maintenance_date')->label('Data')->date(),
                TextColumn::make('type')->label('Tipo')->badge(),
                TextColumn::make('performedBy.name')->label('Eseguita da')->placeholder('—'),
                TextColumn::make('cost')->label('Costo')->money('EUR')->placeholder('—'),
                TextColumn::make('next_maintenance_at')->label('Prossima')->date()->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => ! auth()->user()->isClient()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
