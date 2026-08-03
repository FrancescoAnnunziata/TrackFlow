<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            // Quando il cliente ha aperto il documento la prima volta.
            $table->timestamp('document_viewed_at')->nullable()->after('reminders_sent');

            // Firma grafica: immagine PNG su disco privato + dati di chi ha
            // firmato e traccia tecnica dell'atto (prova dell'accettazione).
            $table->string('signature_path')->nullable()->after('accepted_by');
            $table->string('signer_name')->nullable()->after('signature_path');
            $table->string('signer_role')->nullable()->after('signer_name');
            $table->string('signature_ip', 45)->nullable()->after('signer_role');
            $table->string('signature_user_agent', 500)->nullable()->after('signature_ip');

            // PDF congelato al momento della firma: non cambia più anche se il
            // preventivo viene modificato dopo.
            $table->string('pdf_path')->nullable()->after('signature_user_agent');

            $table->timestamp('rejected_at')->nullable()->after('pdf_path');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn([
                'document_viewed_at',
                'signature_path',
                'signer_name',
                'signer_role',
                'signature_ip',
                'signature_user_agent',
                'pdf_path',
                'rejected_at',
                'rejection_reason',
            ]);
        });
    }
};
