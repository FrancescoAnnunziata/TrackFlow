<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integrazioni dell'esterometro (TD17/TD18/TD19): il commercialista le emette
 * per le fatture estere, lo SdI le consegna al nostro indirizzo telematico e
 * l'import da Fatture in Cloud le portava dentro come fatture passive in più,
 * raddoppiando i costi e lasciando documenti «non pagati» che nessun movimento
 * chiude. Ora restano registrate ma marcate, e collegate alla fattura estera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passive_invoices', function (Blueprint $table) {
            // TD17, TD18 o TD19. Null = fattura passiva normale.
            $table->string('integration_type', 4)->nullable()->after('type')->index();
            // Il riferimento come lo scrive l'XML (DatiFattureCollegate): serve
            // a collegare anche dopo, se la fattura estera arriva più tardi.
            $table->string('integrates_number')->nullable()->after('integration_type');
            $table->date('integrates_date')->nullable()->after('integrates_number');
            $table->foreignId('integrates_id')->nullable()->after('integrates_date')
                ->constrained('passive_invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('passive_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('integrates_id');
            $table->dropColumn(['integration_type', 'integrates_number', 'integrates_date']);
        });
    }
};
