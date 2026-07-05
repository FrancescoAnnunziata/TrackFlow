<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fatturazione "a giornata": si registrano le ore ma si fattura in giornate
     * (blocco di N ore) a una tariffa giornaliera. Giornate = ceil(ore / N).
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->decimal('hours_per_day', 4, 1)->default(8)->after('default_hourly_rate');
            $table->decimal('daily_rate', 10, 2)->nullable()->after('hours_per_day');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['hours_per_day', 'daily_rate']);
        });
    }
};
