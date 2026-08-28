<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anagrafica stabile del dispositivo per il censimento endpoint.
 *
 * Questi campi vengono AGGIORNATI ad ogni import, non duplicati: la storia
 * tecnica sta sulle rilevazioni (device_security_checks).
 *
 * L'hostname e' indicizzato perche' fa da chiave di riconoscimento di riserva
 * quando il BIOS non espone un seriale utile (vedi serial_placeholders in
 * config/inventario_endpoint.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('hostname')->nullable()->after('name');
            $table->string('department')->nullable()->after('location');
            $table->string('lifecycle_stage')->nullable()->after('status');
            $table->string('inventory_assignee')->nullable()->after('assigned_user_id');

            $table->index('hostname');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropIndex(['hostname']);
            $table->dropColumn(['hostname', 'department', 'lifecycle_stage', 'inventory_assignee']);
        });
    }
};
