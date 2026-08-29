<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Il movimento bancario con cui una spesa è stata pagata.
     *
     * È tracciabilità pura, NON una riconciliazione: non alloca denaro, non
     * entra in nessun totale, non compare fra i documenti che giustificano
     * l'uscita. Serve a chiudere la catena scontrino → pagamento → riaddebito
     * al cliente, che oggi si ricostruisce solo passando dalla fattura passiva.
     *
     * La riconciliazione contabile di quel movimento resta separata e va sulla
     * fattura passiva o sul costo: agganciarci anche la spesa conterebbe lo
     * stesso denaro due volte.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('bank_transaction_id')
                ->nullable()
                ->after('passive_invoice_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_transaction_id');
        });
    }
};
