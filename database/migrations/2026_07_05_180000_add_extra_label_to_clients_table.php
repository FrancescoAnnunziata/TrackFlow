<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nome configurabile della riga "extra" ricorrente (accanto all'importo).
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('monthly_extra_label')->nullable()->after('monthly_extra_amount');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('monthly_extra_label');
        });
    }
};
