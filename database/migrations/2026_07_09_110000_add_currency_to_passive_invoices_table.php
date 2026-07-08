<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Valuta della fattura passiva (ISO, es. EUR/USD). Le estere possono essere
     * in valuta diversa (es. Meilisearch in USD). Le fatture esistenti sono tutte
     * in EUR (default), coerente con gli importi già salvati.
     */
    public function up(): void
    {
        Schema::table('passive_invoices', function (Blueprint $table) {
            $table->string('currency', 3)->default('EUR')->after('amount_gross');
        });
    }

    public function down(): void
    {
        Schema::table('passive_invoices', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
