<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un'ora torna a un cliente solo, come prima di aprile.
     *
     * Il molti-a-molti era stato introdotto per il lavoro condiviso fra più
     * clienti, ma in cinque mesi non è mai stato usato: tutte le ore registrate
     * hanno esattamente un cliente. In cambio complicava il form, il filtro,
     * l'export (una riga con due clienti non si sa a chi mandarla) e il calcolo
     * delle ore fatturabili.
     *
     * La conversione è senza perdita proprio perché non c'è nulla da perdere:
     * per prudenza, se un domani esistesse una riga con più clienti, si tiene il
     * primo — è la stessa scelta che faceva il down() della migrazione di aprile.
     */
    public function up(): void
    {
        Schema::table('hours', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('user_id')->constrained();
        });

        DB::table('client_hour')
            ->select('hour_id', DB::raw('MIN(client_id) as client_id'))
            ->groupBy('hour_id')
            ->orderBy('hour_id')
            ->each(function ($riga) {
                DB::table('hours')->where('id', $riga->hour_id)->update(['client_id' => $riga->client_id]);
            });

        Schema::dropIfExists('client_hour');
    }

    public function down(): void
    {
        Schema::create('client_hour', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hour_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        DB::table('hours')->whereNotNull('client_id')->orderBy('id')->each(function ($ora) {
            DB::table('client_hour')->insert([
                'hour_id' => $ora->id,
                'client_id' => $ora->client_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        Schema::table('hours', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
