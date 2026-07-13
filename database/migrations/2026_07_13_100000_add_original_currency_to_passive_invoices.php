<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passive_invoices', function (Blueprint $table): void {
            // Fattura estera in valuta: gli importi restano in EUR (per riconciliare
            // col movimento), qui teniamo l'originale come riferimento.
            $table->string('original_currency', 3)->nullable()->after('currency');
            $table->decimal('original_amount', 12, 2)->nullable()->after('original_currency');
        });
    }

    public function down(): void
    {
        Schema::table('passive_invoices', function (Blueprint $table): void {
            $table->dropColumn(['original_currency', 'original_amount']);
        });
    }
};
