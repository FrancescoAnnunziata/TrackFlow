<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Collega un rimborso alla spesa che lo ha generato (carta personale).
     * Nullable: i rimborsi manuali/trasferte non hanno una spesa d'origine.
     * unique: una spesa genera al massimo un rimborso.
     */
    public function up(): void
    {
        Schema::table('reimbursements', function (Blueprint $table) {
            $table->foreignId('expense_id')->nullable()->after('client_id')->constrained()->cascadeOnDelete();
            $table->unique('expense_id');
        });
    }

    public function down(): void
    {
        Schema::table('reimbursements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_id');
        });
    }
};
