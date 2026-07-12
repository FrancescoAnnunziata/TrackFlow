<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passive_invoices', function (Blueprint $table): void {
            // Fattura passiva anticipata dal dipendente col conto personale e
            // saldata tramite una richiesta di rimborso: si "chiude" quando il
            // bonifico di rimborso viene riconciliato al Reimbursement collegato.
            $table->foreignId('reimbursement_id')
                ->nullable()
                ->after('payment_status')
                ->constrained('reimbursements')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('passive_invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reimbursement_id');
        });
    }
};
