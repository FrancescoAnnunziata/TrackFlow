<?php

namespace App\Filament\Resources\BankTransactions\Pages;

use App\Filament\Resources\BankTransactions\BankTransactionResource;
use App\Filament\Resources\Costi\CostoResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\PassiveInvoices\PassiveInvoiceResource;
use App\Filament\Resources\Reimbursements\ReimbursementResource;
use App\Models\BankTransaction;
use App\Models\Costo;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Reimbursement;
use App\Models\Supplier;
use App\Services\Reconciliation\ReconciliationService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class EditBankTransaction extends EditRecord
{
    protected static string $resource = BankTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->viewReconciliationAction(),
            $this->reconcileWithPassiveAction(),
            $this->markAsCostoWithPdfAction(),
            DeleteAction::make(),
        ];
    }

    /**
     * Riconcilia il movimento con una fattura passiva esistente scelta da un
     * elenco (le non pagate). Visibile solo se resta una quota da riconciliare.
     */
    private function reconcileWithPassiveAction(): Action
    {
        return Action::make('reconcileWithPassive')
            ->label('Riconcilia con fattura passiva')
            ->icon(Heroicon::OutlinedDocumentText)
            ->color('info')
            ->visible(fn (BankTransaction $record): bool => ! $record->isTransfer() && $record->unreconciledAmount() > 0.01)
            ->modalHeading('Riconcilia con una fattura passiva')
            ->fillForm(fn (BankTransaction $record): array => ['amount' => $record->unreconciledAmount()])
            ->schema([
                Select::make('passive_invoice_id')
                    ->label('Fattura passiva')
                    ->options(fn (): array => self::passiveInvoiceOptions())
                    ->searchable()
                    ->required(),
                TextInput::make('amount')
                    ->label('Importo da allocare')
                    ->numeric()
                    ->prefix('EUR')
                    ->step(0.01)
                    ->required(),
            ])
            ->action(function (array $data, BankTransaction $record): void {
                $passive = PassiveInvoice::find($data['passive_invoice_id']);

                if ($passive === null) {
                    Notification::make()->danger()->title('Fattura passiva non trovata')->send();

                    return;
                }

                app(ReconciliationService::class)->attach($record, $passive, (float) $data['amount']);

                Notification::make()->success()->title('Movimento riconciliato alla fattura passiva')->send();
            });
    }

    /**
     * Per le uscite senza fattura (scontrini, ricevute): crea un Costo dai dati
     * del movimento, permette di allegare il giustificativo (PDF/foto) e
     * riconcilia in un colpo solo.
     */
    private function markAsCostoWithPdfAction(): Action
    {
        return Action::make('markAsCostoWithPdf')
            ->label('Riconcilia come costo (con PDF)')
            ->icon(Heroicon::OutlinedReceiptPercent)
            ->color('warning')
            ->visible(fn (BankTransaction $record): bool => $record->direction === BankTransaction::DIRECTION_OUT
                && ! $record->isTransfer()
                && $record->unreconciledAmount() > 0.01)
            ->modalHeading('Segna come costo e allega il giustificativo')
            ->fillForm(fn (BankTransaction $record): array => [
                'description' => str($record->description ?: 'Costo')->limit(80)->value(),
                'amount' => $record->unreconciledAmount(),
            ])
            ->schema([
                TextInput::make('description')
                    ->label('Descrizione')
                    ->required(),
                Select::make('category')
                    ->label('Conto')
                    ->options(fn (): array => self::contoOptions())
                    ->searchable(),
                Select::make('supplier_id')
                    ->label('Fornitore')
                    ->options(fn (): array => Supplier::orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                TextInput::make('amount')
                    ->label('Importo')
                    ->numeric()
                    ->prefix('EUR')
                    ->step(0.01)
                    ->required(),
                FileUpload::make('attachment')
                    ->label('Giustificativo (scontrino/ricevuta, PDF o foto)')
                    ->disk('public')
                    ->directory('costo-attachments')
                    ->acceptedFileTypes(['application/pdf', 'image/*'])
                    ->downloadable()
                    ->openable(),
            ])
            ->action(function (array $data, BankTransaction $record): void {
                $costo = Costo::create([
                    'date' => $record->booked_at,
                    'description' => $data['description'],
                    'category' => $data['category'] ?? null,
                    'supplier_id' => $data['supplier_id'] ?? null,
                    'amount' => (float) $data['amount'],
                    'vat_amount' => 0,
                    'bank_transaction_id' => $record->id,
                    'attachments' => filled($data['attachment'] ?? null) ? [$data['attachment']] : null,
                ]);

                app(ReconciliationService::class)->attach($record, $costo, (float) $data['amount']);

                Notification::make()->success()->title('Costo creato e riconciliato')->send();
            });
    }

    /**
     * Fatture passive non pagate (escluse le note di credito), per la scelta
     * manuale nella riconciliazione.
     *
     * @return array<int, string>
     */
    private static function passiveInvoiceOptions(): array
    {
        return PassiveInvoice::with('supplier')
            ->where('payment_status', '!=', PassiveInvoice::STATUS_PAID)
            ->where('type', '!=', PassiveInvoice::TYPE_CREDIT_NOTE)
            ->latest('document_date')
            ->limit(200)
            ->get()
            ->mapWithKeys(fn (PassiveInvoice $p): array => [$p->id => sprintf(
                '%s — %s (€%s)',
                $p->number,
                $p->supplier->name ?? '—',
                number_format($p->total(), 2, ',', '.'),
            )])
            ->all();
    }

    /**
     * Conti già usati su costi e fatture passive, come suggerimento.
     *
     * @return array<string, string>
     */
    private static function contoOptions(): array
    {
        return Costo::query()->whereNotNull('category')->where('category', '!=', '')->distinct()->pluck('category')
            ->merge(PassiveInvoice::query()->whereNotNull('category')->where('category', '!=', '')->distinct()->pluck('category'))
            ->unique()->sort()->mapWithKeys(fn (string $c): array => [$c => $c])->all();
    }

    /**
     * Bottone che mostra a quali documenti è riconciliato il movimento, con la
     * quota allocata e un link per aprire ciascun documento. Visibile solo se
     * esiste almeno una riconciliazione.
     */
    private function viewReconciliationAction(): Action
    {
        return Action::make('viewReconciliation')
            ->label('Riconciliazione')
            ->icon('heroicon-o-link')
            ->color('success')
            ->visible(fn (BankTransaction $record): bool => $record->reconciliations()->exists())
            ->modalHeading('Documenti riconciliati')
            ->modalWidth(Width::Large)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Chiudi')
            ->modalContent(function (BankTransaction $record) {
                $rows = collect($record->reconciliationDetails())
                    ->map(function (array $d): array {
                        $attachment = $this->documentAttachment($d['model']);

                        return [
                            'label' => $d['label'],
                            'amount' => $d['amount'],
                            'matchedBy' => $d['matchedBy'],
                            'url' => $this->documentUrl($d['model']),
                            'pdfUrl' => $attachment !== null ? Storage::disk('public')->url($attachment) : null,
                            'pdfName' => $attachment !== null ? basename($attachment) : null,
                            'isPdf' => $attachment !== null && str_ends_with(strtolower($attachment), '.pdf'),
                        ];
                    })
                    ->all();

                return view('filament.bank-transactions.reconciliation-list', [
                    'rows' => $rows,
                    'unreconciled' => $record->unreconciledAmount(),
                ]);
            });
    }

    private function documentUrl(?Model $doc): ?string
    {
        return match (true) {
            $doc instanceof Invoice => InvoiceResource::getUrl('edit', ['record' => $doc]),
            $doc instanceof PassiveInvoice => PassiveInvoiceResource::getUrl('edit', ['record' => $doc]),
            $doc instanceof Costo => CostoResource::getUrl('edit', ['record' => $doc]),
            $doc instanceof Reimbursement => ReimbursementResource::getUrl('edit', ['record' => $doc]),
            $doc instanceof Expense => ExpenseResource::getUrl('edit', ['record' => $doc]),
            default => null,
        };
    }

    /**
     * Percorso (sul disco public) del giustificativo allegato al documento, se
     * presente. I diversi tipi memorizzano l'allegato in campi diversi: si
     * preferisce un PDF, altrimenti il primo allegato disponibile.
     */
    private function documentAttachment(?Model $doc): ?string
    {
        $paths = match (true) {
            $doc instanceof PassiveInvoice => [$doc->attachment],
            $doc instanceof Costo, $doc instanceof Reimbursement => $doc->attachments ?? [],
            $doc instanceof Expense => $doc->attachaments ?? [],
            default => [],
        };

        $paths = array_values(array_filter($paths, fn ($p): bool => is_string($p) && $p !== ''));

        if ($paths === []) {
            return null;
        }

        foreach ($paths as $path) {
            if (str_ends_with(strtolower($path), '.pdf')) {
                return $path;
            }
        }

        return $paths[0];
    }
}
