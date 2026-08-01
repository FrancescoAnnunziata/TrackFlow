<?php

use App\Filament\Resources\BankTransactions\Pages\EditBankTransaction;
use App\Filament\Resources\BankTransactions\Pages\ListBankTransactions;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Costo;
use App\Models\PassiveInvoice;
use App\Models\Supplier;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('reconciles a movement to a passive invoice from the edit page', function () {
    $this->actingAs(User::factory()->admin()->create());
    $supplier = Supplier::create(['name' => 'Fornitore SRL']);
    $passive = PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => '12/2026', 'type' => 'expense',
        'document_date' => '2026-03-01', 'amount_net' => 100, 'amount_vat' => 22, 'amount_gross' => 122,
        'payment_status' => PassiveInvoice::STATUS_NOT_PAID,
    ]);
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank']);
    $tx = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-03-05', 'amount' => -122.00,
        'direction' => 'out', 'description' => 'Bonifico fornitore', 'dedup_hash' => 'e1',
    ]);

    Livewire::test(EditBankTransaction::class, ['record' => $tx->getRouteKey()])
        ->callAction(TestAction::make('reconcileWithPassive'), [
            'passive_invoice_id' => $passive->id, 'amount' => 122.00,
        ])
        ->assertHasNoActionErrors();

    expect($tx->fresh()->reconciled)->toBeTrue();
    expect($passive->fresh()->payment_status)->toBe(PassiveInvoice::STATUS_PAID);
});

it('creates a cost and reconciles from the edit page (with-PDF action, no file)', function () {
    $this->actingAs(User::factory()->admin()->create());
    Costo::create(['date' => '2026-01-01', 'description' => 'seed', 'category' => 'Trasferte', 'amount' => 1, 'vat_amount' => 0]);
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank']);
    $tx = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-03-02', 'amount' => -9.90,
        'direction' => 'out', 'description' => 'Scontrino bar', 'dedup_hash' => 'e2',
    ]);

    Livewire::test(EditBankTransaction::class, ['record' => $tx->getRouteKey()])
        ->callAction(TestAction::make('markAsCostoWithPdf'), [
            'description' => 'Bar', 'category' => 'Trasferte', 'amount' => 9.90, 'attachment' => null,
        ])
        ->assertHasNoActionErrors();

    $costo = Costo::where('bank_transaction_id', $tx->id)->first();
    expect($costo)->not->toBeNull();
    expect($costo->category)->toBe('Trasferte');
    expect($tx->fresh()->reconciled)->toBeTrue();
});

it('reconciles a movement to a suggested passive invoice from the table riconcilia action', function () {
    $this->actingAs(User::factory()->admin()->create());
    $supplier = Supplier::create(['name' => 'Fornitore Sugg', 'vat_number' => 'IT12345678901']);
    $passive = PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'S-1', 'type' => 'expense',
        'document_date' => '2026-03-01', 'amount_net' => 100, 'amount_vat' => 0, 'amount_gross' => 100,
        'payment_status' => PassiveInvoice::STATUS_NOT_PAID,
    ]);
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank']);
    $tx = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-03-02', 'amount' => -100.00,
        'direction' => 'out', 'description' => 'Pagamento Fornitore Sugg', 'dedup_hash' => 'ts1',
    ]);

    Livewire::test(ListBankTransactions::class)
        ->callAction(TestAction::make('riconcilia')->table($tx), [
            'documents' => [$passive->getMorphClass().':'.$passive->id],
        ])
        ->assertHasNoActionErrors();

    expect($tx->fresh()->reconciled)->toBeTrue();
    expect($passive->fresh()->payment_status)->toBe(PassiveInvoice::STATUS_PAID);
});

it('reconciles one movement to a combination of documents that sum to its total', function () {
    $this->actingAs(User::factory()->admin()->create());
    $supplier = Supplier::create(['name' => 'Telepass SpA', 'vat_number' => 'IT09771701001']);
    $a = PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'T-A', 'type' => 'expense',
        'document_date' => '2026-07-23', 'amount_net' => 2.60, 'amount_vat' => 0, 'amount_gross' => 2.60,
        'payment_status' => PassiveInvoice::STATUS_NOT_PAID,
    ]);
    $b = PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => 'T-B', 'type' => 'expense',
        'document_date' => '2026-07-23', 'amount_net' => 2.93, 'amount_vat' => 0, 'amount_gross' => 2.93,
        'payment_status' => PassiveInvoice::STATUS_NOT_PAID,
    ]);
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank']);
    $tx = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-07-23', 'amount' => -5.53,
        'direction' => 'out', 'description' => 'ADDEBITO SDD TELEPASS SPA', 'dedup_hash' => 'tp1',
    ]);

    Livewire::test(ListBankTransactions::class)
        ->callAction(TestAction::make('riconcilia')->table($tx), [
            'documents' => [
                $a->getMorphClass().':'.$a->id,
                $b->getMorphClass().':'.$b->id,
            ],
        ])
        ->assertHasNoActionErrors();

    // Il movimento è chiuso e ENTRAMBE le passive risultano pagate.
    expect($tx->fresh()->reconciled)->toBeTrue();
    expect($tx->fresh()->unreconciledAmount())->toBe(0.0);
    expect($a->fresh()->payment_status)->toBe(PassiveInvoice::STATUS_PAID);
    expect($b->fresh()->payment_status)->toBe(PassiveInvoice::STATUS_PAID);
});
