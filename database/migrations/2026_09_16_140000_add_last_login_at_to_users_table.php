<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // L'ultimo accesso fatto davvero con password e codice a due
            // fattori. Non lo aggiorna il rientro automatico da «Ricordami»:
            // è quello l'ancoraggio della finestra settimanale, vedi
            // App\Http\Middleware\ReauthenticateWeekly.
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_login_at');
        });
    }
};
