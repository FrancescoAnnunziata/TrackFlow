<?php

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Tables\InvoicesTable;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function sentInvoice(int $net): Invoice
{
    $user = User::factory()->admin()->create();
    $client = Client::create(['name' => 'Acme', 'invoicing_provider' => Client::PROVIDER_FIC]);
    $invoice = Invoice::create([
        'user_id' => $user->id, 'client_id' => $client->id, 'number' => '3/2026',
        'issue_date' => '2026-06-10', 'period_from' => '2026-06-01', 'period_to' => '2026-06-30',
        'vat_rate' => 0, 'status' => 'sent',
    ]);
    InvoiceItem::create(['invoice_id' => $invoice->id, 'name' => 'Servizi', 'qty' => 1, 'net_price' => $net, 'vat_kind' => InvoiceItem::VAT_STANDARD]);

    return $invoice->load('items');
}

it('marks an active invoice as collected from the Registra incasso table action', function () {
    $invoice = sentInvoice(1000);
    $this->actingAs($invoice->user);
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank']);
    $tx = BankTransaction::create([
        'bank_account_id' => $account->id, 'booked_at' => '2026-06-15', 'amount' => 1000,
        'description' => 'Accredito Acme', 'dedup_hash' => 'i1',
    ]);

    Livewire::test(ListInvoices::class)
        ->callAction(TestAction::make('registra_incasso')->table($invoice), ['transactions' => [$tx->id]]);

    expect($invoice->fresh()->status)->toBe('paid');
    expect($tx->fresh()->reconciled)->toBeTrue();
    expect($tx->fresh()->unreconciledAmount())->toBe(0.0);
});

it('hides Registra incasso for Fiscozen client invoices but shows it for FIC ones', function () {
    $user = User::factory()->admin()->create();
    $this->actingAs($user);

    $make = function (string $provider, string $number) use ($user): Invoice {
        $client = Client::create(['name' => 'Cli '.$provider, 'invoicing_provider' => $provider]);
        $invoice = Invoice::create([
            'user_id' => $user->id, 'client_id' => $client->id, 'number' => $number,
            'issue_date' => '2026-06-10', 'period_from' => '2026-06-01', 'period_to' => '2026-06-30',
            'vat_rate' => 0, 'status' => 'sent',
        ]);
        InvoiceItem::create(['invoice_id' => $invoice->id, 'name' => 'S', 'qty' => 1, 'net_price' => 500, 'vat_kind' => InvoiceItem::VAT_STANDARD]);

        return $invoice;
    };

    $fic = $make(Client::PROVIDER_FIC, '10/2026');
    $fiscozen = $make(Client::PROVIDER_FISCOZEN, '11/2026');

    Livewire::test(ListInvoices::class)
        ->assertActionVisible(TestAction::make('registra_incasso')->table($fic))
        ->assertActionHidden(TestAction::make('registra_incasso')->table($fiscozen));
});

it('suggests only incoming movements within the date/amount window', function () {
    $invoice = sentInvoice(1000);
    $account = BankAccount::create(['name' => 'InBank', 'bank_key' => 'inbank']);
    // Entrata compatibile.
    $good = BankTransaction::create(['bank_account_id' => $account->id, 'booked_at' => '2026-06-15', 'amount' => 1000, 'description' => 'ok', 'dedup_hash' => 'g']);
    // Uscita dello stesso importo: NON deve comparire (è un pagamento, non un incasso).
    BankTransaction::create(['bank_account_id' => $account->id, 'booked_at' => '2026-06-15', 'amount' => -1000, 'description' => 'uscita', 'dedup_hash' => 'o']);
    // Entrata fuori finestra temporale (±45gg).
    BankTransaction::create(['bank_account_id' => $account->id, 'booked_at' => '2026-09-15', 'amount' => 1000, 'description' => 'tardi', 'dedup_hash' => 't']);
    // Entrata fuori finestra importo (>150%).
    BankTransaction::create(['bank_account_id' => $account->id, 'booked_at' => '2026-06-16', 'amount' => 5000, 'description' => 'troppo', 'dedup_hash' => 'b']);

    $ref = new ReflectionMethod(InvoicesTable::class, 'candidateOptions');
    $ref->setAccessible(true);
    $options = $ref->invoke(null, $invoice);

    expect(array_keys($options))->toBe([$good->id]);
});
