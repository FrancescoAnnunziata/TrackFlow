<?php

namespace App\Filament\Resources\Devices\RelationManagers;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FindingsRelationManager extends RelationManager
{
    protected static string $relationship = 'findings';

    protected static ?string $title = 'Criticità';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('title')
                    ->label('Titolo')
                    ->required()
                    ->columnSpanFull(),
                Select::make('severity')
                    ->label('Severità')
                    ->options(FindingSeverity::class)
                    ->default(FindingSeverity::Medium)
                    ->required(),
                Select::make('status')
                    ->label('Stato')
                    ->options(FindingStatus::class)
                    ->default(FindingStatus::Open)
                    ->required(),
                DatePicker::make('due_date')
                    ->label('Scadenza'),
                Textarea::make('description')
                    ->label('Descrizione')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')->label('Criticità')->limit(50),
                TextColumn::make('severity')->label('Severità')->badge(),
                TextColumn::make('status')->label('Stato')->badge(),
                TextColumn::make('due_date')->label('Scadenza')->date()->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => ! auth()->user()->isClient()),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => ! auth()->user()->isClient()),
            ]);
    }
}
