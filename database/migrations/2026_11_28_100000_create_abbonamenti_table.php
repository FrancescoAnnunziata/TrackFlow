<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abbonamenti', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cadence', 20);
            $table->decimal('amount', 10, 2)->nullable();
            $table->date('next_renewal_on');
            $table->date('last_charged_on')->nullable();
            $table->text('notes')->nullable();
            // Il rinnovo per cui la mail di promemoria è già partita: evita di
            // rimandarla ogni mattina della settimana che precede la scadenza.
            $table->date('reminded_for')->nullable();
            $table->timestamps();

            $table->index('next_renewal_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abbonamenti');
    }
};
