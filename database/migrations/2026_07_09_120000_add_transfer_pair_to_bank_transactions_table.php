<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table): void {
            // Movimento gemello di un giroconto tra conti propri (self FK): l'uscita
            // punta all'entrata e viceversa. Null = movimento normale.
            $table->foreignId('transfer_pair_id')
                ->nullable()
                ->after('reconciled')
                ->constrained('bank_transactions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('transfer_pair_id');
        });
    }
};
