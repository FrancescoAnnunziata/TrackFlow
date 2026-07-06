<?php

use App\Filament\Finance\Widgets\CashflowChart;
use App\Filament\Finance\Widgets\ContoEconomicoStats;
use App\Filament\Finance\Widgets\MargineClienteTable;
use App\Filament\Finance\Widgets\SaldoIvaStats;
use App\Filament\Pages\ControlloFinanziario;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function seedFinanceData(): void
{
    $user = User::factory()->admin()->create();
    $client = Client::create(['name' => 'Cliente X', 'vat_number' => 'IT1']);
    $invoice = Invoice::create([
        'user_id' => $user->id, 'client_id' => $client->id, 'number' => '1/2026',
        'issue_date' => now()->startOfYear()->addMonth(), 'period_from' => now()->startOfYear(),
        'period_to' => now()->startOfYear()->addMonth(), 'vat_rate' => 22, 'status' => 'sent',
    ]);
    $invoice->items()->create(['name' => 'Consulenza', 'qty' => 1, 'net_price' => 1000, 'vat_kind' => 'standard', 'line_kind' => 'consulting', 'sort' => 0]);

    $supplier = Supplier::create(['name' => 'Fornitore Y']);
    PassiveInvoice::create([
        'supplier_id' => $supplier->id, 'number' => '10/2026', 'type' => 'expense',
        'document_date' => now()->startOfYear()->addMonth(), 'amount_net' => 300, 'amount_vat' => 66, 'amount_gross' => 366,
    ]);

    $account = BankAccount::create(['name' => 'Conto', 'bank_key' => 'generic', 'opening_balance' => 0]);
    BankTransaction::create(['bank_account_id' => $account->id, 'booked_at' => now()->startOfYear()->addMonth(), 'amount' => 1220, 'description' => 'Incasso', 'dedup_hash' => 'a']);
    BankTransaction::create(['bank_account_id' => $account->id, 'booked_at' => now()->startOfYear()->addMonth(), 'amount' => -366, 'description' => 'Pagamento', 'dedup_hash' => 'b']);
}

it('renders the finance dashboard page for admins', function () {
    seedFinanceData();
    $admin = User::where('role', 'admin')->first();

    Livewire::actingAs($admin)->test(ControlloFinanziario::class)->assertOk();
});

it('renders each finance widget without error', function () {
    seedFinanceData();
    $admin = User::where('role', 'admin')->first();

    foreach ([ContoEconomicoStats::class, SaldoIvaStats::class, CashflowChart::class, MargineClienteTable::class] as $widget) {
        Livewire::actingAs($admin)->test($widget)->assertOk();
    }
});

it('denies access to non-admins', function () {
    $member = User::factory()->create(); // role member
    $this->actingAs($member);
    expect(ControlloFinanziario::canAccess())->toBeFalse();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    expect(ControlloFinanziario::canAccess())->toBeTrue();
});
