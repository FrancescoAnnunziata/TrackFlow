<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Righe forfait di dettaglio: consente di splittare l'importo forfait
     * mensile in più righe con descrizioni diverse (es. Fedespedi: 1800 + 1800).
     * Se valorizzata, sostituisce la riga unica forfait_amount.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->json('forfait_lines')->nullable()->after('forfait_amount');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('forfait_lines');
        });
    }
};
