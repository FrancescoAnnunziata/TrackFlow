<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Il numero fattura non è più inventato da TrackFlow: lo assegna Fatture in
     * Cloud all'invio (o si compila a mano per i clienti esterni tipo Fiscozen).
     * Le bozze quindi possono non averlo.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('number')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('number')->nullable(false)->change();
        });
    }
};
