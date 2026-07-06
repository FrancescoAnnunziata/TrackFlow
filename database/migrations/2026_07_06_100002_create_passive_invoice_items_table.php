<?php

use App\Models\PassiveInvoice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Righe delle fatture passive: servono alla precisione dell'IVA a credito
     * (somma dei vat_amount di riga) e al dettaglio per categoria di costo.
     */
    public function up(): void
    {
        Schema::create('passive_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(PassiveInvoice::class)->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('qty', 10, 2)->default(1);
            $table->string('measure', 8)->nullable();
            $table->decimal('net_price', 12, 2)->default(0);
            $table->decimal('vat_value', 5, 2)->default(0);   // aliquota % applicata
            $table->decimal('vat_amount', 12, 2)->default(0); // IVA della riga
            $table->string('category')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passive_invoice_items');
    }
};
