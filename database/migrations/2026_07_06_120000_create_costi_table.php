<?php

use App\Models\BankTransaction;
use App\Models\Supplier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Costi aziendali senza fattura passiva (commissioni bancarie, F24/tasse,
     * bolli, stipendi). Registrabili a mano; opzionalmente legati a un
     * movimento bancario e/o a un fornitore.
     */
    public function up(): void
    {
        Schema::create('costi', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->string('description');
            $table->string('category')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('vat_amount', 12, 2)->nullable();
            $table->foreignIdFor(Supplier::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(BankTransaction::class)->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('costi');
    }
};
