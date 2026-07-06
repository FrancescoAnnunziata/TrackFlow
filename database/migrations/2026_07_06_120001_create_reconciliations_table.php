<?php

use App\Models\BankTransaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Riconciliazione: collega un movimento bancario a un documento (fattura
     * attiva, fattura passiva o costo). `amount` è la quota allocata, così un
     * movimento può coprire più documenti o coprirne uno solo in parte.
     */
    public function up(): void
    {
        Schema::create('reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(BankTransaction::class)->constrained()->cascadeOnDelete();
            $table->string('reconcilable_type');
            $table->unsignedBigInteger('reconcilable_id');
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('matched_by')->default('manual'); // manual | auto
            $table->unsignedTinyInteger('confidence')->default(0);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['reconcilable_type', 'reconcilable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliations');
    }
};
