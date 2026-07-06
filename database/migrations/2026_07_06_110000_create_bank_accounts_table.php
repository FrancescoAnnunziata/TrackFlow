<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conti bancari. bank_key seleziona il preset di import CSV (vivid, inbank,
     * generic). Il saldo iniziale ancora il calcolo del saldo corrente.
     */
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('bank_key')->default('generic'); // vivid | inbank | generic
            $table->string('iban')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->date('opening_balance_date')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
