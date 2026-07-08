<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Percorso del PDF della fattura passiva (giustificativo). Serve per le
     * fatture estere caricate a mano: il link al PDF va nel report del
     * commercialista.
     */
    public function up(): void
    {
        Schema::table('passive_invoices', function (Blueprint $table) {
            $table->string('attachment')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('passive_invoices', function (Blueprint $table) {
            $table->dropColumn('attachment');
        });
    }
};
