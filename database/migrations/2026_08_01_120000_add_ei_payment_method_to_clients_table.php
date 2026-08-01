<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modalità di pagamento SDI (ModalitaPagamento: MP01, MP05, ...) per cliente.
 * Serve nell'XML della fattura elettronica: senza, FIC non genera il documento.
 * Null = usa il default di config (services.fic.ei_payment_method).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('ei_payment_method', 4)->nullable()->after('payment_method_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('ei_payment_method');
        });
    }
};
