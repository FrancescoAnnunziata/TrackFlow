<?php

namespace App\Filament\Pages;

use App\Jobs\ExtractIssuedInvoicesJob;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Ai\IssuedInvoiceExtractor;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importa in TrackFlow le fatture emesse fuori da Fatture in Cloud (Fiscozen,
 * che non ha API) partendo dalle copie di cortesia in PDF: Claude ne estrae i
 * dati, l'utente li rivede e conferma. Le fatture già presenti (stesso cliente e
 * numero) vengono saltate, così si possono ricaricare gli stessi file senza
 * doppioni. Solo admin.
 */
class FattureEmessePdf extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowUp;

    protected static string|\UnitEnum|null $navigationGroup = 'Controllo Finanziario';

    protected static ?string $title = 'Fatture emesse da PDF';

    protected static ?string $navigationLabel = 'Fatture emesse da PDF';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.fatture-emesse-pdf';

    private const DISK = 'public';

    private const DIR = 'issued-invoice-pdfs';

    /** @var array<string, mixed> */
    public array $data = [];

    /** Chiave cache del job di estrazione in corso (null = nessuna estrazione). */
    public ?string $extractKey = null;

    /** True mentre il job di estrazione è in corso: guida il polling nella view. */
    public bool $extracting = false;

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->isAdmin();
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('1. Carica i PDF')
                    ->description('Trascina qui le fatture emesse in PDF (anche più di una), poi premi "Estrai dati".')
                    ->components([
                        FileUpload::make('files')
                            ->label('PDF fatture emesse')
                            ->multiple()
                            ->acceptedFileTypes(['application/pdf'])
                            ->maxSize(20480)
                            ->disk(self::DISK)
                            ->directory(self::DIR)
                            ->visibility('public')
                            ->storeFiles()
                            ->downloadable()
                            ->openable(),
                    ]),
                Section::make('2. Rivedi i dati estratti')
                    ->description('Controlla soprattutto il cliente: se il PDF non combacia con l\'anagrafica va scelto a mano.')
                    ->visible(fn (): bool => filled($this->data['rows'] ?? null))
                    ->components([
                        Repeater::make('rows')
                            ->label('')
                            ->addable(false)
                            ->reorderable(false)
                            ->defaultItems(0)
                            ->columns(3)
                            ->itemLabel(fn (array $state): string => trim(
                                ($state['number'] ?? '—').' · '.($state['extracted_client'] ?? '')
                            ))
                            ->schema([
                                Hidden::make('attachment'),
                                Hidden::make('extracted_client'),
                                Hidden::make('source_name'),
                                Select::make('client_id')
                                    ->label('Cliente')
                                    ->options(fn (): array => Client::orderBy('name')->pluck('name', 'id')->all())
                                    ->searchable()
                                    ->required()
                                    ->helperText(fn (Get $get): string => 'Sul PDF: '.($get('extracted_client') ?: '—'))
                                    ->columnSpan(2),
                                TextInput::make('number')->label('Numero')->required(),
                                DatePicker::make('issue_date')->label('Data fattura')->required(),
                                DatePicker::make('period_from')->label('Periodo dal')->required(),
                                DatePicker::make('period_to')->label('Periodo al')->required(),
                                TextInput::make('vat_rate')->label('IVA (%)')->numeric()->default(0)->required(),
                                TextInput::make('total')
                                    ->label('Totale atteso')
                                    ->numeric()
                                    ->helperText('Solo controllo: se non torna con le righe, l\'import lo segnala.'),
                                Toggle::make('is_credit_note')
                                    ->label('Nota di credito')
                                    ->default(false)
                                    ->live(),
                                Select::make('replaces_invoice_id')
                                    ->label('Sostituisce la bozza')
                                    ->options(fn (Get $get): array => self::draftOptions($get))
                                    ->placeholder('Nessuna — crea una fattura nuova')
                                    ->helperText('La bozza scelta viene aggiornata coi dati del PDF (numero compreso) invece di crearne una seconda. Proposta in automatico solo se il totale coincide.')
                                    ->columnSpan(2),
                                Repeater::make('lines')
                                    ->label('Righe')
                                    ->columnSpanFull()
                                    ->columns(6)
                                    ->defaultItems(0)
                                    ->schema([
                                        TextInput::make('name')->label('Descrizione')->required()->columnSpan(4),
                                        TextInput::make('qty')->label('Q.tà')->numeric()->default(1)->required(),
                                        TextInput::make('net_price')->label('Prezzo unit.')->numeric()->required(),
                                    ]),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Bozze rimpiazzabili per il cliente e il tipo scelti nella riga, con
     * periodo e totale in etichetta: sono quelli che permettono di riconoscerle,
     * visto che un numero ancora non ce l'hanno.
     *
     * @return array<int, string>
     */
    private static function draftOptions(Get $get): array
    {
        $clientId = (int) ($get('client_id') ?? 0);
        if ($clientId === 0) {
            return [];
        }

        $type = ($get('is_credit_note') ?? false) ? Invoice::TYPE_CREDIT_NOTE : Invoice::TYPE_INVOICE;

        return ExtractIssuedInvoicesJob::draftCandidates($clientId, $type)
            ->mapWithKeys(fn (Invoice $invoice): array => [
                $invoice->id => sprintf(
                    '%s — %s (%s €)',
                    $invoice->number ?: 'senza numero',
                    optional($invoice->period_from)->format('m/Y') ?? '—',
                    number_format($invoice->total(), 2, ',', '.'),
                ),
            ])
            ->all();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('estrai')
                ->label(fn (): string => $this->extracting ? 'Estrazione in corso…' : 'Estrai dati dai PDF')
                ->icon(Heroicon::OutlinedSparkles)
                ->disabled(fn (): bool => $this->extracting)
                ->action('estrai'),
            Action::make('crea')
                ->label('Crea fatture')
                ->icon(Heroicon::OutlinedCheck)
                ->color('success')
                ->visible(fn (): bool => filled($this->data['rows'] ?? null))
                ->requiresConfirmation()
                ->modalDescription('Crea in TrackFlow le fatture elencate. Quelle già presenti (stesso cliente e numero) vengono saltate.')
                ->action('crea'),
        ];
    }

    public function estrai(IssuedInvoiceExtractor $extractor): void
    {
        if (! $extractor->configured()) {
            Notification::make()->danger()->title('Chiave API Anthropic non configurata')->send();

            return;
        }

        // getState() dehydrata la form: sposta i file caricati sul disco e
        // restituisce i path definitivi.
        $state = $this->form->getState();
        $paths = array_values($state['files'] ?? []);
        if ($paths === []) {
            Notification::make()->warning()->title('Nessun PDF caricato')->send();

            return;
        }

        $key = 'emesse-extract:'.auth()->id().':'.Str::uuid();
        Cache::put($key, ['status' => 'processing'], now()->addHour());
        ExtractIssuedInvoicesJob::dispatch($paths, $key, self::DISK);

        $this->extractKey = $key;
        $this->extracting = true;

        Notification::make()->info()
            ->title('Estrazione avviata')
            ->body('Sto elaborando '.count($paths).' PDF in background: i dati compariranno qui appena pronti.')
            ->send();
    }

    /**
     * Interrogata in polling dalla view mentre l'estrazione è in corso.
     */
    public function checkExtraction(): void
    {
        if (! $this->extracting || blank($this->extractKey)) {
            return;
        }

        $result = Cache::get($this->extractKey);
        if (! is_array($result) || ($result['status'] ?? null) === 'processing') {
            return; // ancora in corso
        }

        $key = $this->extractKey;
        $this->extracting = false;
        $this->extractKey = null;
        Cache::forget($key);

        if (($result['status'] ?? null) === 'failed') {
            Notification::make()->danger()->title('Estrazione fallita')->body($result['message'] ?? '')->send();

            return;
        }

        $rows = $result['rows'] ?? [];
        $errors = (int) ($result['errors'] ?? 0);

        // fill() rigenera i componenti figli del Repeater dallo stato.
        $this->form->fill([...$this->data, 'rows' => $rows]);

        Notification::make()->success()
            ->title('Estratti '.count($rows).' documenti'.($errors > 0 ? " ({$errors} falliti)" : ''))
            ->send();
    }

    public function crea(): void
    {
        $rows = $this->form->getState()['rows'] ?? [];

        $created = 0;
        $replaced = 0;
        $skipped = 0;
        $mismatches = [];

        foreach ($rows as $row) {
            $clientId = (int) ($row['client_id'] ?? 0);
            $number = trim((string) ($row['number'] ?? ''));
            $type = ($row['is_credit_note'] ?? false) ? Invoice::TYPE_CREDIT_NOTE : Invoice::TYPE_INVOICE;

            if ($clientId === 0 || $number === '') {
                $skipped++;

                continue;
            }

            // Bozza da rimpiazzare: dev'essere ancora dello stesso cliente e tipo
            // (l'utente può aver cambiato il cliente dopo l'estrazione).
            $target = filled($row['replaces_invoice_id'] ?? null)
                ? Invoice::where('client_id', $clientId)
                    ->where('type', $type)
                    ->find($row['replaces_invoice_id'])
                : null;

            // Idempotenza: l'unique è (client_id, number, type), quindi ricaricare
            // gli stessi PDF non crea doppioni. La bozza che stiamo rimpiazzando
            // non conta come conflitto: è proprio quella che vogliamo aggiornare.
            $conflict = Invoice::where('client_id', $clientId)
                ->where('number', $number)
                ->where('type', $type)
                ->when($target !== null, fn ($q) => $q->whereKeyNot($target->id))
                ->exists();

            if ($conflict) {
                $skipped++;

                continue;
            }

            $lines = array_values($row['lines'] ?? []);
            if ($lines === []) {
                $skipped++;

                continue;
            }

            $invoice = DB::transaction(function () use ($row, $clientId, $number, $type, $lines, $target): Invoice {
                $attributes = [
                    'user_id' => $target?->user_id ?? auth()->id(),
                    'client_id' => $clientId,
                    'number' => $number,
                    'type' => $type,
                    'issue_date' => $row['issue_date'],
                    'period_from' => $row['period_from'],
                    'period_to' => $row['period_to'],
                    'vat_rate' => round((float) ($row['vat_rate'] ?? 0), 2),
                    // Emesse davvero: lo stato di incasso lo decide la riconciliazione.
                    'status' => 'sent',
                    'imported' => true,
                    'attachment' => $row['attachment'] ?? null,
                ];

                if ($target !== null) {
                    // Rimpiazzo: il PDF è la verità sulle righe, quindi le vecchie
                    // vanno via. Ore e spese agganciate restano: appartengono al
                    // periodo, non alle righe, e servono alla tracciabilità.
                    $target->update($attributes);
                    $target->items()->delete();
                    $invoice = $target;
                } else {
                    $invoice = Invoice::create($attributes);
                }

                foreach ($lines as $i => $line) {
                    InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'name' => (string) $line['name'],
                        'qty' => round((float) $line['qty'], 2),
                        'net_price' => round((float) $line['net_price'], 2),
                        'vat_kind' => InvoiceItem::VAT_STANDARD,
                        'sort' => $i,
                    ]);
                }

                return $invoice;
            });

            // Il totale del PDF fa da controprova sulle righe estratte. Se non
            // torna, la fattura si crea lo stesso (i dati sono correggibili dalla
            // scheda) ma va segnalato: un silenzio qui sarebbe un dato sbagliato
            // entrato in contabilità senza che nessuno se ne accorga.
            $expected = round((float) ($row['total'] ?? 0), 2);
            $actual = $invoice->fresh('items')->total();
            if ($expected > 0 && abs($actual - $expected) > 0.02) {
                $mismatches[] = sprintf('%s (PDF %s, righe %s)', $number,
                    number_format($expected, 2, ',', '.'),
                    number_format($actual, 2, ',', '.'),
                );
            }

            $target !== null ? $replaced++ : $created++;
        }

        $this->afterCreate($created, $replaced, $skipped, $mismatches);
    }

    /**
     * @param  array<int, string>  $mismatches
     */
    private function afterCreate(int $created, int $replaced, int $skipped, array $mismatches): void
    {
        $this->data['rows'] = [];
        $this->data['files'] = [];
        $this->form->fill($this->data);

        $body = [];
        if ($replaced > 0) {
            $body[] = "{$replaced} bozze aggiornate col numero del PDF.";
        }
        if ($skipped > 0) {
            $body[] = "{$skipped} saltate (già presenti o incomplete).";
        }
        if ($mismatches !== []) {
            $body[] = 'Totale diverso dal PDF: '.implode(', ', $mismatches).'.';
        }

        Notification::make()->success()
            ->title("Create {$created} fatture")
            ->body(implode(' ', $body) ?: null)
            ->send();
    }
}
