<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            // Con quale intestazione esce il documento (chiave in
            // config/azienda.php). Null = quella di default.
            $table->string('issuer_key', 50)->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('issuer_key');
        });
    }
};
