<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Collegamento della fattura al documento creato su Fatture in Cloud.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('fic_document_id')->nullable()->after('status');
            $table->string('fic_document_token')->nullable()->after('fic_document_id');
            $table->timestamp('fic_sent_at')->nullable()->after('fic_document_token');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['fic_document_id', 'fic_document_token', 'fic_sent_at']);
        });
    }
};
