<?php

namespace App\Filament\Pages;

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
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

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
                ->label('Estrai dati dai PDF')
                ->icon(Heroicon::OutlinedSparkles)
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

        $rows = [];
        $errors = 0;

        foreach ($paths as $path) {
            try {
                $pdf = $this->readPdf($path);
                if (blank($pdf)) {
                    throw new \RuntimeException('PDF non leggibile (vuoto).');
                }
                $d = $extractor->extract($pdf);
                // Chiave UUID: il Repeater di Filament indicizza gli item per chiave,
                // un array numerico ne rompe il rendering (getStateSnapshot on null).
                $rows[(string) \Illuminate\Support\Str::uuid()] = [
                    'attachment' => $path,
                    'supplier_name' => $d['supplier_name'],
                    'supplier_vat' => $d['supplier_vat'],
                    'number' => $d['number'],
                    'document_date' => $d['document_date'],
                    'category' => $d['category'],
                    'currency' => $d['currency'],
                    'amount_net' => $d['amount_net'],
                    'amount_vat' => $d['amount_vat'],
                    'amount_gross' => $d['amount_gross'],
                ];
            } catch (\Throwable $e) {
                $errors++;
                Notification::make()->warning()->title('Estrazione fallita per un PDF')->body($e->getMessage())->send();
            }
        }

        // fill() rigenera i componenti figli del Repeater dallo stato: settare
        // $this->data['rows'] direttamente non li istanzia e il render fallisce.
        $this->form->fill([...$this->data, 'files' => $paths, 'rows' => $rows]);

        Notification::make()->success()
            ->title('Estratti '.count($rows).' documenti'.($errors > 0 ? " ({$errors} falliti)" : ''))
            ->send();
    }

    /**
     * Legge il contenuto binario del PDF caricato, gestendo sia i path sul disco
     * public sia (per robustezza) un eventuale file temporaneo o assoluto.
     */
    private function readPdf(mixed $path): ?string
    {
        if ($path instanceof \Illuminate\Http\UploadedFile) {
            return file_get_contents($path->getRealPath()) ?: null;
        }

        if (! is_string($path) || $path === '') {
            return null;
        }

        if (Storage::disk(self::DISK)->exists($path)) {
            return Storage::disk(self::DISK)->get($path) ?: null;
        }

        return is_file($path) ? (file_get_contents($path) ?: null) : null;
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
                'type' => 'expense',
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
