<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabella pivot per associare un utente a piu' clienti. La colonna
     * users.client_id resta come "cliente principale" (default per la
     * creazione dei record e per la retrocompatibilita'); il pivot rappresenta
     * l'insieme completo delle associazioni.
     */
    public function up(): void
    {
        Schema::create('client_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['client_id', 'user_id']);
        });

        // Backfill: ogni utente con un cliente principale diventa membro di
        // quel cliente anche nel pivot, cosi' l'associazione esistente e'
        // visibile anche dal lato Cliente.
        DB::table('users')->whereNotNull('client_id')->orderBy('id')->each(function ($user) {
            DB::table('client_user')->insertOrIgnore([
                'client_id' => $user->client_id,
                'user_id' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_user');
    }
};
