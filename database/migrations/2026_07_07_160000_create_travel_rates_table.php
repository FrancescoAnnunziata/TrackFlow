<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabella "Mapping" delle trasferte, per utente: ad ogni "Tipo trasferta"
     * (la stessa chiave che si imposta come Luogo di lavoro su Google Calendar,
     * es. FIORAVANTI) corrisponde tragitto DA/A, oggetto e chilometraggio.
     * L'importo del rimborso e' km * tariffa €/km dell'utente.
     */
    public function up(): void
    {
        Schema::create('travel_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tipo');
            $table->string('from_location')->nullable();
            $table->string('to_location')->nullable();
            $table->string('purpose')->nullable();
            $table->decimal('km', 8, 2)->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_rates');
    }
};
