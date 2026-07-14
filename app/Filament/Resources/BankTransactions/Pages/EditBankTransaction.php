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
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class EditBankTransaction extends EditRecord
{
    protected static string $resource = BankTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->viewReconciliationAction(),
            DeleteAction::make(),
        ];
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
