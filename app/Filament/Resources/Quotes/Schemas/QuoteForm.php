<?php

namespace App\Filament\Resources\Quotes\Schemas;

use App\Models\Quote;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class QuoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Intestazione')
                    ->columns(2)
                    ->components([
                        TextInput::make('number')
                            ->label('Numero')
                            ->required()
                            ->maxLength(50)
                            ->default(fn () => self::suggestNextNumber()),
                        DatePicker::make('issue_date')
                            ->label('Data')
                            ->required()
                            ->default(now()),
                        Select::make('client_id')
                            ->label('Cliente')
                            ->relationship('client', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('status')
                            ->label('Stato')
                            ->options(self::statusOptions())
                            ->default(Quote::STATUS_DRAFT)
                            ->required()
                            ->disabled(fn (?Quote $record): bool => $record !== null)
                            ->dehydrated()
                            ->helperText('Lo stato avanza con le azioni Invia / Accetta / Genera fattura.'),
                    ]),

                Section::make('Intervento e stima')
                    ->columns(2)
                    ->components([
                        Textarea::make('description')
                            ->label('Descrizione intervento')
                            ->rows(4)
                            ->columnSpanFull(),
                        TextInput::make('estimated_hours')
                            ->label('Ore stimate')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.5)
                            ->required()
                            ->live(onBlur: true),
                        TextInput::make('hourly_rate')
                            ->label('Tariffa oraria (€/h)')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->required()
                            ->live(onBlur: true),
                        TextInput::make('vat_rate')
                            ->label('IVA (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.01)
                            ->default(22)
                            ->required()
                            ->live(onBlur: true),
                        Placeholder::make('total_preview')
                            ->label('Totale (IVA inclusa)')
                            ->content(function (Get $get): string {
                                $hours = (float) ($get('estimated_hours') ?: 0);
                                $rate = (float) ($get('hourly_rate') ?: 0);
                                $vat = (float) ($get('vat_rate') ?: 0);
                                $total = $hours * $rate * (1 + $vat / 100);

                                return '€ ' . number_format($total, 2, ',', '.');
                            }),
                    ]),

                Section::make('Note')
                    ->components([
                        Textarea::make('notes')
                            ->label('')
                            ->rows(3),
                    ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            Quote::STATUS_DRAFT => 'Bozza',
            Quote::STATUS_SENT => 'Inviato',
            Quote::STATUS_ACCEPTED => 'Accettato',
            Quote::STATUS_REJECTED => 'Rifiutato',
            Quote::STATUS_INVOICED => 'Fatturato',
        ];
    }

    private static function suggestNextNumber(): string
    {
        $year = now()->year;
        $count = Quote::whereYear('issue_date', $year)->count();

        return sprintf('P%d-%03d', $year, $count + 1);
    }
}
