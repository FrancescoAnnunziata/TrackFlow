<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Client;
use App\Services\Billing\InvoiceBuilder;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    /**
     * Espone lo stato dei filtri della tabella nella query string, così i
     * filtri (cliente, stato, periodo) sono condivisibili via link.
     */
    public function queryString(): array
    {
        return [
            'tableFilters' => [],
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->generateAction(),
            CreateAction::make()
                ->label('Crea vuota')
                ->icon(Heroicon::OutlinedPlus)
                ->color('gray'),
        ];
    }

    /**
     * Genera una bozza dal motore: scegli cliente e periodo, le righe vengono
     * costruite dalla configurazione del cliente (tariffe dal profilo + override
     * per utente, ore raggruppate, spese in art. 15).
     */
    private function generateAction(): Action
    {
        return Action::make('generate')
            ->label('Genera fattura')
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->color('primary')
            ->modalHeading('Genera fattura da cliente e periodo')
            ->modalSubmitActionLabel('Genera')
            ->schema([
                Select::make('client_id')
                    ->label('Cliente')
                    ->options(fn (): array => Client::orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                DatePicker::make('period_start')
                    ->label('Inizio periodo')
                    ->native(false)
                    ->displayFormat('m/Y')
                    ->default(now()->startOfMonth())
                    ->required()
                    ->helperText('Primo mese del periodo. La durata segue la periodicità configurata sul cliente.'),
            ])
            ->action(function (array $data) {
                $client = Client::findOrFail($data['client_id']);
                $invoice = app(InvoiceBuilder::class)->build($client, Carbon::parse($data['period_start']));

                Notification::make()
                    ->success()
                    ->title('Bozza fattura generata')
                    ->body("Fattura {$invoice->number} creata. Controllala e poi inviala a Fatture in Cloud.")
                    ->send();

                return redirect(InvoiceResource::getUrl('edit', ['record' => $invoice]));
            });
    }
}
