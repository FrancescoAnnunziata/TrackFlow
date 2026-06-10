<?php

use App\Models\Expense;
use App\Models\Invoice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_invoice', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Invoice::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Expense::class)->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['invoice_id', 'expense_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_invoice');
    }
};
