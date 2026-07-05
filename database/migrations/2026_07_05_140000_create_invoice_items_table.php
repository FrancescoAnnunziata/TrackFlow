<?php

use App\Models\Invoice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Righe esplicite della fattura, generate dal motore di fatturazione.
     * Sono la fonte dei totali e del payload FIC (le pivot ore/spese restano
     * solo per tracciabilità di cosa la fattura copre).
     */
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Invoice::class)->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('qty', 10, 2)->default(1);
            $table->string('measure', 8)->nullable();       // 'h' oppure ''
            $table->decimal('net_price', 12, 2)->default(0);
            $table->string('vat_kind')->default('standard'); // standard | art15
            $table->string('line_kind')->default('consulting'); // consulting|advance|reconciliation|extra|expenses
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
