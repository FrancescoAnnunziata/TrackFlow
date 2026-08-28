<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I controlli storici diventano nullable, come lo erano gia' mfa_enabled,
 * backup_configured e le due policy.
 *
 * Con l'import del censimento serve poter dire "non valutato": lo script non
 * riesce sempre a leggere tutto (dipende dai privilegi con cui gira) e un
 * default a false farebbe nascere criticita' inventate, per esempio "antivirus
 * non aggiornato" solo perche' l'eta' delle firme non era leggibile.
 */
return new class extends Migration
{
    private const FLAGS = [
        'os_updated',
        'antivirus_active',
        'antivirus_updated',
        'firewall_active',
        'disk_encryption_active',
        'screen_lock_active',
        'admin_user_disabled',
    ];

    public function up(): void
    {
        Schema::table('device_security_checks', function (Blueprint $table) {
            foreach (self::FLAGS as $flag) {
                $table->boolean($flag)->nullable()->default(null)->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('device_security_checks', function (Blueprint $table) {
            foreach (self::FLAGS as $flag) {
                $table->boolean($flag)->nullable(false)->default(false)->change();
            }
        });
    }
};
