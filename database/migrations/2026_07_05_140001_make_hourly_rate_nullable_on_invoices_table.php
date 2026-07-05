<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le fatture generate dal motore (forfait, righe esplicite) non hanno una
     * singola tariffa oraria: hourly_rate diventa opzionale.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('hourly_rate', 10, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('hourly_rate', 10, 2)->nullable(false)->change();
        });
    }
};
