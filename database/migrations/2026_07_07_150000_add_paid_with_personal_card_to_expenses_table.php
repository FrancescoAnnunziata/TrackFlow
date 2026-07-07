<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Di default le spese si intendono pagate con carta aziendale. Se true, la
     * spesa e' stata anticipata con carta personale e genera automaticamente un
     * rimborso (vedi ExpenseObserver).
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->boolean('paid_with_personal_card')->default(false)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('paid_with_personal_card');
        });
    }
};
