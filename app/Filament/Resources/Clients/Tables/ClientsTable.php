<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Client;
use App\Services\Billing\InvoiceBuilder;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                ImageColumn::make('logo')
                    ->disk('public')
                    ->circular(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('generateInvoice')
                    ->label('Genera fattura')
                    ->icon(Heroicon::OutlinedDocumentPlus)
                    ->color('primary')
                    ->visible(fn (Client $record): bool => auth()->user()->isAdmin() && $record->isBillableHere())
                    ->modalHeading('Genera fattura del periodo')
                    ->modalSubmitActionLabel('Genera')
                    ->schema([
                        DatePicker::make('period_start')
                            ->label('Inizio periodo')
                            ->native(false)
                            ->displayFormat('m/Y')
                            ->default(now()->startOfMonth())
                            ->required()
                            ->helperText('Primo mese del periodo da fatturare. La durata segue la periodicità configurata sul cliente.'),
                    ])
                    ->action(function (Client $record, array $data) {
                        $invoice = app(InvoiceBuilder::class)->build($record, Carbon::parse($data['period_start']));

                        Notification::make()
                            ->success()
                            ->title('Bozza fattura generata')
                            ->body("Fattura {$invoice->number} creata. Controllala e poi inviala a Fatture in Cloud.")
                            ->send();

                        return redirect(InvoiceResource::getUrl('edit', ['record' => $invoice]));
                    }),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
