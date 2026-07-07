<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rimborsi spese verso l'utente. Tre tipologie (vedi ReimbursementType):
     * trasferta, spesa con carta personale, aggiunta manuale. Distinto dalle
     * "Spese" (Expense), che sono invece i costi ribaltati sul cliente.
     */
    public function up(): void
    {
        Schema::create('reimbursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->default('manuale');
            $table->string('status')->default('pending');
            $table->date('date');
            $table->decimal('amount', 10, 2);
            $table->text('notes')->nullable();
            $table->json('attachments')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reimbursements');
    }
};
