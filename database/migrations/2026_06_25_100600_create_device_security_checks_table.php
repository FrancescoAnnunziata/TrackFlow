<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_security_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('checked_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('checked_at');
            $table->boolean('os_updated')->default(false);
            $table->boolean('antivirus_active')->default(false);
            $table->boolean('antivirus_updated')->default(false);
            $table->boolean('firewall_active')->default(false);
            $table->boolean('disk_encryption_active')->default(false);
            $table->boolean('screen_lock_active')->default(false);
            $table->boolean('admin_user_disabled')->default(false);
            $table->boolean('mfa_enabled')->nullable();
            $table->boolean('backup_configured')->nullable();
            $table->boolean('usb_policy_ok')->nullable();
            $table->boolean('password_policy_ok')->nullable();
            $table->string('risk_level')->default('low');
            $table->string('outcome')->default('compliant');
            $table->text('notes')->nullable();
            $table->date('next_check_at')->nullable();
            $table->timestamps();

            $table->index('device_id');
            $table->index('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_security_checks');
    }
};
