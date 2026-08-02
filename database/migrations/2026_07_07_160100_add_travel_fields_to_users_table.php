<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dati del veicolo e tariffa chilometrica dell'utente, usati per generare i
     * rimborsi trasferta e per compilare l'intestazione della nota spese.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('vehicle_plate')->nullable();
            $table->string('vehicle_model')->nullable();
            $table->decimal('km_rate', 8, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['vehicle_plate', 'vehicle_model', 'km_rate']);
        });
    }
};
