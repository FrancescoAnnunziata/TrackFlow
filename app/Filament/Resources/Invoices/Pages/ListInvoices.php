<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Client;
use App\Models\Expense;
use App\Services\Billing\InvoiceBuilder;
use App\Support\PeriodoFatturazione;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

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
                    ->live()
                    ->required(),
                DatePicker::make('period_start')
                    ->label('Inizio periodo')
                    ->native(false)
                    ->displayFormat('m/Y')
                    ->default(now()->startOfMonth())
                    ->live(onBlur: true)
                    ->required()
                    ->helperText('Primo mese del periodo. La durata segue la periodicità configurata sul cliente.'),
                Placeholder::make('riepilogo')
                    ->hiddenLabel()
                    ->visible(fn (Get $get): bool => filled($get('client_id')))
                    ->content(fn (Get $get): HtmlString => $this->riepilogo(
                        (int) $get('client_id'),
                        $get('period_start'),
                    )),
            ])
            ->action(function (array $data) {
                $client = Client::findOrFail($data['client_id']);
                $invoice = app(InvoiceBuilder::class)->build($client, Carbon::parse($data['period_start']));

                Notification::make()
                    ->success()
                    ->title('Bozza fattura generata')
                    ->body('Bozza creata. Controllala, poi emettila (Fatture in Cloud per i clienti FIC, a mano su Fiscozen per gli altri).')
                    ->send();

                return redirect(InvoiceResource::getUrl('edit', ['record' => $invoice]));
            });
    }

    /**
     * Riepilogo di come è configurato il cliente scelto, mostrato dentro il
     * modale prima di generare: canale, IVA, modello di fatturazione e — la
     * parte che si sbaglia più spesso — da quale periodo arrivano le spese.
     *
     * Sui clienti anticipati con conguaglio (Alsea) il periodo delle spese non
     * è quello fatturato ma il precedente: scritto qui si vede prima di
     * generare, invece di scoprirlo dalla fattura vuota.
     */
    public function riepilogo(int $clientId, ?string $periodStart): HtmlString
    {
        $client = Client::find($clientId);

        if ($client === null) {
            return new HtmlString('');
        }

        $righe = [
            'Canale' => match ($client->invoicing_provider) {
                Client::PROVIDER_FIC => '<strong>Fatture in Cloud</strong> — si invia da qui col pulsante «Invia a Fatture in Cloud»',
                Client::PROVIDER_FISCOZEN => '<strong>Fiscozen</strong> — da <u>ricreare a mano</u> su Fiscozen, qui resta la brutta copia',
                default => '<strong>Gestionale esterno</strong> — non si invia da qui',
            },
        ];

        $iva = (float) ($client->vat_rate ?? 22);
        $righe['IVA'] = $iva > 0
            ? number_format($iva, 0).'% sulle righe standard'
            : '<strong>0%</strong> — regime forfettario, nessuna IVA da aggiungere';

        $righe['Fatturazione'] = implode(' · ', array_filter([
            $this->modello($client),
            $this->periodicita((int) $client->billing_period_months),
            $client->billing_timing === Client::TIMING_ADVANCE ? '<strong>anticipato</strong>' : 'posticipato',
        ]));

        $periodo = filled($periodStart)
            ? PeriodoFatturazione::per($client, Carbon::parse($periodStart))
            : null;

        if ($periodo !== null) {
            $righe['Periodo fatturato'] = $periodo->etichettaPeriodo();

            if ($periodo->conguaglioDa !== null) {
                $righe['Conguaglio ore'] = PeriodoFatturazione::etichetta($periodo->conguaglioDa, $periodo->conguaglioA)
                    .' — ore oltre il minimo garantito del periodo già fatturato';
            }

            $righe['Rimborsi spese'] = $this->rimborsi($client, $periodo);
        }

        $html = '<div style="display:flex; flex-direction:column; gap:.35rem; font-size:.8rem; line-height:1.45;">';

        foreach ($righe as $etichetta => $valore) {
            $html .= '<div style="display:flex; gap:.5rem;">'
                .'<span style="flex:0 0 8.5rem; opacity:.6;">'.e($etichetta).'</span>'
                .'<span>'.$valore.'</span>'
                .'</div>';
        }

        return new HtmlString($html.'</div>');
    }

    private function modello(Client $client): string
    {
        return match ($client->billing_model) {
            Client::MODEL_FORFAIT => 'forfait '.$this->euro($client->forfait_amount).'/mese',
            Client::MODEL_DAILY => 'a giornata '.$this->euro($client->daily_rate).'/gg',
            default => 'a ore '.$this->euro($client->default_hourly_rate).'/h',
        };
    }

    private function periodicita(int $mesi): string
    {
        return match (max(1, $mesi)) {
            1 => 'mensile',
            3 => 'trimestrale',
            6 => 'semestrale',
            12 => 'annuale',
            default => "ogni {$mesi} mesi",
        };
    }

    /**
     * Le spese che finirebbero in fattura, con il periodo da cui arrivano: è la
     * riga che evita di generare la fattura sul trimestre sbagliato.
     */
    private function rimborsi(Client $client, PeriodoFatturazione $periodo): string
    {
        $spese = Expense::query()
            ->where('client_id', $client->id)
            ->whereDate('date', '>=', $periodo->speseDa)
            ->whereDate('date', '<=', $periodo->speseA)
            ->whereDoesntHave('invoices')
            ->get();

        $finestra = $periodo->etichettaSpese();

        $testo = $spese->isEmpty()
            ? "nessuna spesa da riaddebitare in {$finestra}"
            : sprintf(
                '<strong>%d %s</strong> per %s da %s, in art. 15',
                $spese->count(),
                $spese->count() === 1 ? 'spesa' : 'spese',
                $this->euro($spese->sum('amount')),
                $finestra,
            );

        if ($periodo->speseSfasate()) {
            $testo .= '<br><span style="opacity:.75;">⚠️ Cliente anticipato: le spese arrivano dal periodo <u>precedente</u>, non da quello fatturato.</span>';
        }

        return $testo;
    }

    private function euro(mixed $importo): string
    {
        return '€ '.number_format((float) $importo, 2, ',', '.');
    }
}
