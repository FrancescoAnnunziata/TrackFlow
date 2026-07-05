<?php

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tariffa oraria per (cliente, utente): es. ore di Giorgio 90€, ore di
     * Annunziata 40€ sullo stesso cliente. In assenza di override, il motore
     * usa clients.default_hourly_rate.
     */
    public function up(): void
    {
        Schema::create('client_user_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Client::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->decimal('hourly_rate', 8, 2);
            $table->timestamps();

            $table->unique(['client_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_user_rates');
    }
};
