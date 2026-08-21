<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colonne per l'autenticazione a due fattori di Filament (TOTP).
 *
 * Entrambe sono `text` e non `string`: i valori vengono cifrati con APP_KEY
 * (cast "encrypted" / "encrypted:array" applicati dai trait di Filament) e il
 * ciphertext e' molto piu' lungo del segreto in chiaro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('app_authentication_secret')->nullable()->after('password');
            $table->text('app_authentication_recovery_codes')->nullable()->after('app_authentication_secret');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['app_authentication_secret', 'app_authentication_recovery_codes']);
        });
    }
};
