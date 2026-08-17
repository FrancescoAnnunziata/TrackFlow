<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fatture che nascono da un sistema esterno (oggi: gli abbonamenti di
 * personal-ticketing, via API).
 *
 * `source` + `source_id` sono la chiave di idempotenza: l'unique impedisce che
 * lo stesso pagamento generi due fatture, qualunque cosa succeda ai retry del
 * chiamante. `subject` e `ei_payment_method` sono override per-fattura di
 * valori che finora vivevano solo sull'anagrafica cliente: una fattura di
 * abbonamento pagata con carta non può ereditare "Consulenza" e "bonifico"
 * dal cliente di consulenza con la stessa P.IVA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('source')->nullable()->after('imported');
            $table->string('source_id')->nullable()->after('source');
            $table->string('subject')->nullable()->after('notes');
            $table->string('ei_payment_method', 4)->nullable()->after('subject');

            $table->unique(['source', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique(['source', 'source_id']);
            $table->dropColumn(['source', 'source_id', 'subject', 'ei_payment_method']);
        });
    }
};
