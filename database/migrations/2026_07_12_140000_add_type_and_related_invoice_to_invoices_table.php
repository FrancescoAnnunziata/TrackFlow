<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            // 'invoice' | 'credit_note' — le note di credito attive stornano una
            // fattura emessa (cliente paga meno perché una quota non è dovuta).
            $table->string('type')->default('invoice')->after('status');
            // Fattura stornata da questa nota di credito (collegamento manuale in UI).
            $table->foreignId('related_invoice_id')->nullable()->after('type')
                ->constrained('invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('related_invoice_id');
            $table->dropColumn('type');
        });
    }
};
