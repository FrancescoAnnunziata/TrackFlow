<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Incassi giornalieri dell'e-commerce (P.IVA personale, regime forfettario).
     * Non è un registro fiscale da trasmettere — il forfettario è esonerato
     * dalla tenuta delle scritture contabili e il commercio elettronico
     * indiretto non invia corrispettivi telematici. Serve a un fine solo:
     * sommare questi incassi alle fatture per sapere a che punto si è della
     * soglia annua del regime, che Shopify da solo non può sapere.
     */
    public function up(): void
    {
        Schema::create('corrispettivi', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            // Da dove arriva il dato: 'shopify' lo scrive lo scheduler e viene
            // risovrascritto a ogni sync, 'manuale' resta come lo scrivi tu.
            $table->string('channel', 50)->default('shopify');
            // Lordo incassato: è il numero che conta per la soglia forfettaria,
            // al lordo delle commissioni Shopify/Stripe (non si deducono).
            $table->decimal('gross', 12, 2)->default(0);
            // Rimborsi maturati sugli ordini di quel giorno. Cumulativo: un reso
            // che arriva a distanza di giorni riduce il netto del giorno d'ordine.
            $table->decimal('refunds', 12, 2)->default(0);
            $table->unsignedInteger('orders_count')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Una riga per giorno per canale: rende il sync idempotente.
            $table->unique(['date', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corrispettivi');
    }
};
