<?php

namespace App\Filament\Resources\Emittenti\Tables;

use App\Models\Emittente;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EmittentiTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nome')->label('Nome'),
                TextColumn::make('partita_iva')->label('P.IVA')->placeholder('—'),
                TextColumn::make('canale_invio')
                    ->label('Canale di invio')
                    ->formatStateUsing(fn (?string $state): string => Emittente::canaleLabel($state)),
                TextColumn::make('regime_fiscale')
                    ->label('Regime abituale')
                    ->formatStateUsing(fn (?string $state): string => Emittente::regimi()[$state] ?? (string) $state),
                IconColumn::make('principale')->label('Società')->boolean(),
                TextColumn::make('clients_count')->label('Clienti')->counts('clients'),
            ])
            ->defaultSort('id')
            ->paginated(false)
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
