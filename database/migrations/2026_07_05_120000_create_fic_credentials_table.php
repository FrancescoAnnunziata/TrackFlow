<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Credenziali OAuth2 di Fatture in Cloud. Tabella a riga singola: TrackFlow
     * è collegato a una sola azienda FIC. I token sono cifrati a riposo dal cast
     * del modello.
     */
    public function up(): void
    {
        Schema::create('fic_credentials', function (Blueprint $table) {
            $table->id();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('expires_at')->nullable();
            $table->string('company_id')->nullable();
            $table->string('company_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fic_credentials');
    }
};
