<?php

use App\Models\Supplier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fatture passive (di acquisto) importate da Fatture in Cloud come archivio
     * dei costi. Idempotenti: l'import fa upsert su fic_document_id (univoco).
     */
    public function up(): void
    {
        Schema::create('passive_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Supplier::class)->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('fic_document_id')->nullable()->unique();
            $table->string('fic_document_token')->nullable();
            $table->string('number')->nullable();
            $table->string('type')->default('expense'); // expense | passive_invoice | ...
            $table->date('document_date')->index();
            $table->date('due_date')->nullable();
            $table->decimal('amount_net', 12, 2)->default(0);
            $table->decimal('amount_vat', 12, 2)->default(0);
            $table->decimal('amount_gross', 12, 2)->default(0);
            $table->string('category')->nullable();
            $table->string('payment_status')->default('not_paid'); // not_paid | paid
            $table->boolean('imported')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passive_invoices');
    }
};
