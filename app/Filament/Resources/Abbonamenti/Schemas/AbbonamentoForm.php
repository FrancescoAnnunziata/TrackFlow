<?php

namespace App\Filament\Resources\Abbonamenti\Schemas;

use App\Models\Abbonamento;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AbbonamentoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->helperText('Cosa si rinnova, per esempio «Vimeo» o «Dominio dolcitaliaexchange.it».')
                    ->required(),
                Select::make('supplier_id')
                    ->label('Fornitore')
                    ->relationship(name: 'supplier', titleAttribute: 'name')
                    ->searchable()
                    ->preload(),
                Select::make('cadence')
                    ->label('Cadenza')
                    ->options(Abbonamento::cadenceOptions())
                    ->default(Abbonamento::CADENCE_ANNUAL)
                    ->required(),
                TextInput::make('amount')
                    ->label('Importo dell\'ultimo rinnovo')
                    ->numeric()
                    ->prefix('EUR')
                    ->step(0.01),
                DatePicker::make('next_renewal_on')
                    ->label('Prossimo rinnovo')
                    ->helperText('La mail di promemoria parte una settimana prima di questa data.')
                    ->required(),
                DatePicker::make('last_charged_on')
                    ->label('Ultimo addebito'),
                Textarea::make('notes')
                    ->label('Note')
                    ->helperText('Compaiono nella mail di promemoria.')
                    ->columnSpanFull(),
            ]);
    }
}
