<?php

use App\Models\BankAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Movimenti bancari importati da CSV. amount è con segno (positivo=entrata,
     * negativo=uscita). dedup_hash + bank_account_id garantiscono l'idempotenza
     * dell'import (ri-caricare lo stesso estratto non duplica).
     */
    public function up(): void
    {
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(BankAccount::class)->constrained()->cascadeOnDelete();
            $table->date('booked_at');
            $table->date('value_date')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('direction', 3); // in | out
            $table->text('description')->nullable();
            $table->string('counterparty')->nullable();
            $table->string('bank_reference')->nullable();
            $table->string('dedup_hash');
            $table->boolean('reconciled')->default(false);
            $table->json('raw')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['bank_account_id', 'dedup_hash']);
            $table->index('booked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};
