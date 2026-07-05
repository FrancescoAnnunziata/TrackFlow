<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Segna le fatture importate dallo storico di Fatture in Cloud (archivio in
     * sola lettura) e indicizza il collegamento al documento FIC.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('imported')->default(false)->after('fic_sent_at');
            $table->index('fic_document_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['fic_document_id']);
            $table->dropColumn('imported');
        });
    }
};
