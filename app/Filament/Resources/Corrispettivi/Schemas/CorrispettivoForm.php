<?php

namespace App\Filament\Resources\Corrispettivi\Schemas;

use App\Models\Corrispettivo;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CorrispettivoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('date')
                    ->label('Giorno')
                    ->default(now())
                    ->required(),
                Select::make('channel')
                    ->label('Origine')
                    ->options([
                        Corrispettivo::CHANNEL_SHOPIFY => 'Shopify',
                        Corrispettivo::CHANNEL_MANUAL => 'Manuale',
                    ])
                    ->default(Corrispettivo::CHANNEL_MANUAL)
                    ->helperText('Le righe Shopify vengono riscritte dal sync notturno: per correzioni tue usa "Manuale".')
                    ->required(),
                TextInput::make('gross')
                    ->label('Incassato lordo')
                    ->numeric()
                    ->prefix('EUR')
                    ->step(0.01)
                    ->required()
                    ->helperText('Al lordo delle commissioni Shopify/Stripe: nel forfettario non si deducono.'),
                TextInput::make('refunds')
                    ->label('Resi e rimborsi')
                    ->numeric()
                    ->prefix('EUR')
                    ->step(0.01)
                    ->default(0),
                TextInput::make('orders_count')
                    ->label('Numero ordini')
                    ->numeric()
                    ->integer()
                    ->default(0),
                Textarea::make('notes')
                    ->label('Note')
                    ->columnSpanFull(),
            ]);
    }
}
