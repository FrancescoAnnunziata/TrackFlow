<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campi tecnici della singola rilevazione prodotta dallo script PowerShell.
 *
 * Ogni giro di censimento aggiunge una riga qui (mai un update di quelle
 * precedenti): device_security_checks e' la tabella "Rilevazione", collegata al
 * dispositivo e datata con checked_at, ed e' quella su cui si legge
 * l'evoluzione nel tempo dei campi critici.
 *
 * I booleani sono tutti nullable perche' il CSV ha tre stati: "SI", "NO" e
 * "N/D" / "N/D (serve admin)". Perdere la distinzione fra "no" e "non
 * rilevabile" falserebbe le segnalazioni (es. TamperProtection non leggibile
 * senza privilegi admin non e' la stessa cosa di TamperProtection disattivata).
 * Dove il valore porta con se' un dettaglio utile ("NO (workgroup)",
 * "NO - password admin locale non gestita") si conserva la stringa originale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_security_checks', function (Blueprint $table) {
            // Provenienza della rilevazione: 'manual' (form) o 'inventory_csv'.
            $table->string('source')->default('manual')->after('checked_at');
            $table->string('detected_by')->nullable()->after('source');
            $table->boolean('ran_as_admin')->nullable()->after('detected_by');

            // Identita' (snapshot: l'hostname puo' cambiare fra un giro e l'altro)
            $table->string('hostname')->nullable();
            $table->decimal('ram_gb', 8, 2)->nullable();
            $table->text('disks')->nullable();
            $table->string('logged_user')->nullable();
            $table->date('profile_last_used_at')->nullable();

            // Sistema operativo
            $table->string('os_name')->nullable();
            $table->string('os_edition')->nullable();
            $table->string('os_version')->nullable();
            $table->string('os_build')->nullable();
            $table->string('os_architecture')->nullable();
            $table->string('os_support')->nullable();
            $table->date('os_installed_at')->nullable();
            $table->timestamp('last_reboot_at')->nullable();
            $table->integer('days_since_reboot')->nullable();

            // Patch
            $table->date('last_patch_at')->nullable();
            $table->string('last_patch_kb')->nullable();
            $table->integer('days_since_last_patch')->nullable();
            $table->boolean('reboot_pending')->nullable();

            // Antivirus
            $table->string('av_product')->nullable();
            $table->string('av_third_party')->nullable();
            $table->boolean('av_realtime')->nullable();
            $table->boolean('av_service_active')->nullable();
            $table->date('av_signatures_updated_at')->nullable();
            $table->integer('av_signatures_age_days')->nullable();
            $table->boolean('av_tamper_protection')->nullable();
            $table->date('av_last_scan_at')->nullable();

            // Cifratura
            $table->string('bitlocker_status')->nullable();
            $table->string('bitlocker_protection')->nullable();
            $table->string('bitlocker_method')->nullable();
            $table->string('bitlocker_protectors')->nullable();
            $table->boolean('bitlocker_recovery_key_present')->nullable();
            $table->string('bitlocker_key_location')->nullable();
            $table->boolean('tpm_present')->nullable();
            $table->boolean('tpm_ready')->nullable();
            $table->boolean('secure_boot')->nullable();

            // Account
            $table->text('admin_group_members')->nullable();
            $table->string('builtin_admin_name')->nullable();
            $table->string('builtin_admin_status')->nullable();
            $table->boolean('builtin_admin_renamed')->nullable();
            $table->string('laps')->nullable();
            $table->text('local_active_accounts')->nullable();

            // Rete
            $table->string('firewall_profiles')->nullable();
            $table->boolean('rdp_enabled')->nullable();
            $table->text('screen_lock_policy')->nullable();
            $table->integer('screen_timeout_ac_seconds')->nullable();

            // Gestione
            $table->boolean('azure_ad_joined')->nullable();
            $table->boolean('domain_joined')->nullable();
            $table->string('domain_membership')->nullable();
            $table->boolean('mdm_enrolled')->nullable();
            $table->string('mdm_url')->nullable();

            // Software
            $table->integer('installed_software_count')->nullable();
            $table->text('critical_apps')->nullable();
            $table->text('remote_control_tools')->nullable();
            $table->string('onedrive_status')->nullable();

            // Backup
            $table->string('backup_type')->nullable();
            $table->date('backup_last_ok_at')->nullable();
            $table->date('backup_last_restore_test_at')->nullable();

            // Riga CSV originale: conserva anche le colonne che lo script
            // dovesse aggiungere in futuro e non ancora mappate a colonna.
            $table->json('raw_row')->nullable();

            $table->index(['device_id', 'checked_at']);
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::table('device_security_checks', function (Blueprint $table) {
            $table->dropIndex(['device_id', 'checked_at']);
            $table->dropIndex(['source']);

            $table->dropColumn([
                'source', 'detected_by', 'ran_as_admin',
                'hostname', 'ram_gb', 'disks', 'logged_user', 'profile_last_used_at',
                'os_name', 'os_edition', 'os_version', 'os_build', 'os_architecture',
                'os_support', 'os_installed_at', 'last_reboot_at', 'days_since_reboot',
                'last_patch_at', 'last_patch_kb', 'days_since_last_patch', 'reboot_pending',
                'av_product', 'av_third_party', 'av_realtime', 'av_service_active',
                'av_signatures_updated_at', 'av_signatures_age_days', 'av_tamper_protection', 'av_last_scan_at',
                'bitlocker_status', 'bitlocker_protection', 'bitlocker_method', 'bitlocker_protectors',
                'bitlocker_recovery_key_present', 'bitlocker_key_location', 'tpm_present', 'tpm_ready', 'secure_boot',
                'admin_group_members', 'builtin_admin_name', 'builtin_admin_status', 'builtin_admin_renamed',
                'laps', 'local_active_accounts',
                'firewall_profiles', 'rdp_enabled', 'screen_lock_policy', 'screen_timeout_ac_seconds',
                'azure_ad_joined', 'domain_joined', 'domain_membership', 'mdm_enrolled', 'mdm_url',
                'installed_software_count', 'critical_apps', 'remote_control_tools', 'onedrive_status',
                'backup_type', 'backup_last_ok_at', 'backup_last_restore_test_at',
                'raw_row',
            ]);
        });
    }
};
