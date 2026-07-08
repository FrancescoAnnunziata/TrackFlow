<?php

use App\Models\PassiveInvoice;
use App\Models\Supplier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Campi contabili sulla spesa: il conto (piano dei conti), il fornitore e la
     * fattura passiva corrispondente (se richiesta). Il cliente diventa opzionale
     * perché non tutte le spese vengono riaddebitate (es. software aziendale).
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('conto')->nullable()->after('amount');
            $table->foreignIdFor(Supplier::class)->nullable()->after('client_id')
                ->constrained()->nullOnDelete();
            $table->foreignIdFor(PassiveInvoice::class)->nullable()->after('supplier_id')
                ->constrained()->nullOnDelete();
        });

        // Il cliente diventa opzionale (la FK resta invariata).
        Schema::table('expenses', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('passive_invoice_id');
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn('conto');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable(false)->change();
        });
    }
};
