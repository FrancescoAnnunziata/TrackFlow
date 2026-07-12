<?php

namespace App\Filament\Pages;

use App\Jobs\ExtractForeignInvoicesJob;
use App\Models\PassiveInvoice;
use App\Models\Supplier;
use App\Services\Ai\ForeignInvoiceExtractor;
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
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Carica fatture passive estere in PDF (SiteGround, Meilisearch, ...): Claude
 * ne estrae i dati, l'utente li rivede e conferma la creazione delle fatture
 * passive, col PDF allegato come giustificativo. Solo admin.
 */
class FattureEstere extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeEuropeAfrica;

    protected static string|\UnitEnum|null $navigationGroup = 'Controllo Finanziario';

    protected static ?string $title = 'Fatture estere';

    protected static ?string $navigationLabel = 'Fatture estere';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.fatture-estere';

    private const DISK = 'public';

    private const DIR = 'passive-attachments';

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
                    ->description('Trascina qui le fatture estere in PDF, poi premi "Estrai dati".')
                    ->components([
                        FileUpload::make('files')
                            ->label('PDF fatture')
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
                    ->visible(fn (): bool => filled($this->data['rows'] ?? null))
                    ->components([
                        Repeater::make('rows')
                            ->label('')
                            ->addable(false)
                            ->reorderable(false)
                            ->defaultItems(0)
                            ->columns(3)
                            ->schema([
                                Hidden::make('attachment'),
                                TextInput::make('supplier_name')->label('Fornitore')->required()->columnSpan(2),
                                TextInput::make('supplier_vat')->label('P.IVA'),
                                TextInput::make('number')->label('Numero'),
                                DatePicker::make('document_date')->label('Data')->required(),
                                Select::make('category')->label('Conto')
                                    ->options(fn (): array => collect(ForeignInvoiceExtractor::conti())->mapWithKeys(fn ($c) => [$c => $c])->all())
                                    ->searchable(),
                                Select::make('currency')->label('Valuta')
                                    ->options(['EUR' => 'EUR', 'USD' => 'USD', 'GBP' => 'GBP', 'CHF' => 'CHF'])
                                    ->default('EUR')->required(),
                                TextInput::make('amount_net')->label('Imponibile')->numeric()->required(),
                                TextInput::make('amount_vat')->label('IVA')->numeric()->default(0)->required(),
                                TextInput::make('amount_gross')->label('Totale')->numeric()->required(),
                                Toggle::make('is_credit_note')
                                    ->label('Nota di credito')
                                    ->helperText('Storna il costo invece di aggiungerlo.')
                                    ->default(false),
                            ]),
                    ]),
            ])
            ->statePath('data');
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
                ->label('Crea fatture passive')
                ->icon(Heroicon::OutlinedCheck)
                ->color('success')
                ->visible(fn (): bool => filled($this->data['rows'] ?? null))
                ->requiresConfirmation()
                ->action('crea'),
        ];
    }

    public function estrai(ForeignInvoiceExtractor $extractor): void
    {
        if (! $extractor->configured()) {
            Notification::make()->danger()->title('Chiave API Anthropic non configurata')->send();

            return;
        }

        // getState() dehydrata la form: sposta i file caricati sul disco e
        // restituisce i path definitivi (leggerli da $this->data grezzo darebbe i
        // riferimenti temporanei di Livewire, non ancora sul disco).
        $state = $this->form->getState();
        $paths = array_values($state['files'] ?? []);
        if ($paths === []) {
            Notification::make()->warning()->title('Nessun PDF caricato')->send();

            return;
        }

        // L'estrazione (una chiamata a Claude per PDF) è troppo lenta per la
        // richiesta web: la mandiamo in coda e la view interroga il risultato in
        // polling (checkExtraction). Evita il timeout con molti PDF insieme.
        $key = 'estere-extract:'.auth()->id().':'.Str::uuid();
        Cache::put($key, ['status' => 'processing'], now()->addHour());
        ExtractForeignInvoicesJob::dispatch($paths, $key, self::DISK);

        $this->extractKey = $key;
        $this->extracting = true;

        Notification::make()->info()
            ->title('Estrazione avviata')
            ->body('Sto elaborando '.count($paths).' PDF in background: i dati compariranno qui appena pronti.')
            ->send();
    }

    /**
     * Interrogata in polling dalla view mentre l'estrazione è in corso: quando il
     * job ha finito, carica le righe estratte nella tabella di revisione.
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

        // fill() rigenera i componenti figli del Repeater dallo stato: settare
        // $this->data['rows'] direttamente non li istanzia e il render fallisce.
        $this->form->fill([...$this->data, 'rows' => $rows]);

        Notification::make()->success()
            ->title('Estratti '.count($rows).' documenti'.($errors > 0 ? " ({$errors} falliti)" : ''))
            ->send();
    }

    public function crea(): void
    {
        $rows = $this->data['rows'] ?? [];
        $created = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $supplier = $this->resolveSupplier((string) $row['supplier_name'], $row['supplier_vat'] ?? null);

            // Idempotenza: salta se esiste già una passiva stesso fornitore+numero.
            $exists = PassiveInvoice::where('supplier_id', $supplier->id)
                ->where('number', $row['number'])
                ->when(blank($row['number']), fn ($q) => $q->whereRaw('1=0'))
                ->exists();
            if ($exists) {
                $skipped++;

                continue;
            }

            $net = round((float) $row['amount_net'], 2);
            $vat = round((float) $row['amount_vat'], 2);
            $gross = round((float) $row['amount_gross'], 2);

            PassiveInvoice::create([
                'supplier_id' => $supplier->id,
                'number' => $row['number'],
                // Le note di credito stornano il costo: tipo dedicato (conta negativo).
                'type' => ($row['is_credit_note'] ?? false) ? PassiveInvoice::TYPE_CREDIT_NOTE : 'expense',
                'document_date' => $row['document_date'],
                'amount_net' => $net,
                'amount_vat' => $vat,
                'amount_gross' => $gross > 0 ? $gross : round($net + $vat, 2),
                'currency' => $row['currency'] ?? 'EUR',
                'category' => $row['category'],
                'payment_status' => PassiveInvoice::STATUS_NOT_PAID,
                'imported' => false,
                'attachment' => $row['attachment'] ?? null,
            ]);
            $created++;
        }

        $this->data['rows'] = [];
        $this->data['files'] = [];
        $this->form->fill($this->data);

        Notification::make()->success()
            ->title("Create {$created} fatture passive".($skipped > 0 ? " ({$skipped} già presenti)" : ''))
            ->send();
    }

    private function resolveSupplier(string $name, ?string $vat): Supplier
    {
        $vat = trim((string) $vat);
        if ($vat !== '' && $supplier = Supplier::where('vat_number', $vat)->first()) {
            return $supplier;
        }

        $supplier = Supplier::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])->first();
        if ($supplier !== null) {
            if ($vat !== '' && blank($supplier->vat_number)) {
                $supplier->update(['vat_number' => $vat]);
            }

            return $supplier;
        }

        return Supplier::create(['name' => trim($name), 'vat_number' => $vat ?: null]);
    }
}
