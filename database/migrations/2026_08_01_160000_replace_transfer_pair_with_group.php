<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sostituisce il giroconto 1↔1 (transfer_pair_id reciproco) con una "partita di
 * giro" a gruppo: tutti i movimenti che si compensano condividono lo stesso
 * transfer_group_id (l'id più piccolo del gruppo, come àncora). Il caso 1↔1
 * resta un gruppo di due; supporta anche l'uno-a-molti (es. un rimborso a fronte
 * di più uscite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('transfer_group_id')->nullable()->after('reconciled')->index();
        });

        // I giroconti esistenti (coppie reciproche) diventano gruppi di due:
        // entrambe le righe prendono come gruppo l'id più piccolo della coppia.
        // CASE e non LEAST(): SQLite (usato dai test) non ha quella funzione.
        DB::statement(<<<'SQL'
            UPDATE bank_transactions
            SET transfer_group_id = CASE WHEN transfer_pair_id < id THEN transfer_pair_id ELSE id END
            WHERE transfer_pair_id IS NOT NULL
        SQL);

        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('transfer_pair_id');
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->foreignId('transfer_pair_id')->nullable()->after('reconciled')
                ->constrained('bank_transactions')->nullOnDelete();
        });

        // Ricostruzione best-effort del gemello per i gruppi di due (l'uno-a-molti
        // non è rappresentabile come coppia reciproca e resta senza gemello).
        DB::statement(<<<'SQL'
            UPDATE bank_transactions a
            JOIN bank_transactions b
              ON a.transfer_group_id = b.transfer_group_id AND a.id <> b.id
            SET a.transfer_pair_id = b.id
            WHERE a.transfer_group_id IS NOT NULL
              AND (SELECT c FROM (SELECT transfer_group_id AS g, COUNT(*) AS c FROM bank_transactions GROUP BY transfer_group_id) t WHERE t.g = a.transfer_group_id) = 2
        SQL);

        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->dropColumn('transfer_group_id');
        });
    }
};
