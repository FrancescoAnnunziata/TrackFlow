<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro delle chiamate ricevute dalle API in ingresso, comprese quelle
 * rifiutate per firma non valida. Quando fra sei mesi una fattura risulterà
 * sbagliata, la domanda sarà "cosa mi era arrivato esattamente": la risposta
 * deve stare qui, non nei log applicativi che ruotano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('source')->nullable();          // dichiarato nel corpo, non verificato
            $table->string('method', 8);
            $table->string('path');
            $table->string('ip', 45)->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->unsignedSmallInteger('status')->nullable();
            $table->longText('payload')->nullable();       // corpo della richiesta, come arrivato
            $table->longText('response')->nullable();      // corpo della risposta restituita
            $table->timestamps();

            $table->index(['source', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};
