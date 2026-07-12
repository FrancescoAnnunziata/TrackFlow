<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('costi', function (Blueprint $table): void {
            // Costo senza fattura (km, pasti, ...) parte di una richiesta di
            // rimborso: entra nel conto economico ed è coperto dal bonifico di
            // rimborso riconciliato al Reimbursement collegato.
            $table->foreignId('reimbursement_id')
                ->nullable()
                ->after('supplier_id')
                ->constrained('reimbursements')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('costi', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reimbursement_id');
        });
    }
};
