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
                    ->map(fn (array $d): array => [
                        'label' => $d['label'],
                        'amount' => $d['amount'],
                        'matchedBy' => $d['matchedBy'],
                        'url' => $this->documentUrl($d['model']),
                    ])
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
}
