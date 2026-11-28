<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data di incasso della fattura. Serve alla P.IVA personale: il forfettario
 * conta i ricavi per cassa, e quelle fatture non passano dai conti di G8 Labs,
 * quindi non c'è riconciliazione bancaria che dica quando sono state pagate.
 * Per una nota di credito è la data del rimborso, se c'è stato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->date('paid_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('paid_at');
        });
    }
};
