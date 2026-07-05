<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configurazione di fatturazione per-cliente. Guida il motore che genera le
     * fatture: nessuna regola è hardcoded, tutto vive qui.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Provider: solo 'fatture_in_cloud' è emettibile da TrackFlow.
            $table->string('invoicing_provider')->default('fatture_in_cloud')->after('ei_code');

            // Modello: 'forfait' (importo fisso) | 'hourly' (a ore).
            $table->string('billing_model')->default('hourly')->after('invoicing_provider');

            // Periodicità e timing.
            $table->unsignedTinyInteger('billing_period_months')->default(1)->after('billing_model');
            $table->string('billing_timing')->default('arrears')->after('billing_period_months'); // arrears | advance
            $table->boolean('reconcile_previous_period')->default(false)->after('billing_timing');

            // Importi/tariffe.
            $table->decimal('forfait_amount', 10, 2)->nullable()->after('reconcile_previous_period');
            $table->decimal('default_hourly_rate', 8, 2)->nullable()->after('forfait_amount');
            $table->decimal('minimum_hours_per_month', 6, 2)->nullable()->after('default_hourly_rate');
            $table->decimal('monthly_extra_amount', 10, 2)->nullable()->after('minimum_hours_per_month');

            // Presentazione / FIC.
            $table->decimal('vat_rate', 5, 2)->default(22)->after('monthly_extra_amount');
            $table->string('consulting_label')->nullable()->after('vat_rate');
            $table->unsignedBigInteger('payment_method_id')->nullable()->after('consulting_label');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'invoicing_provider',
                'billing_model',
                'billing_period_months',
                'billing_timing',
                'reconcile_previous_period',
                'forfait_amount',
                'default_hourly_rate',
                'minimum_hours_per_month',
                'monthly_extra_amount',
                'vat_rate',
                'consulting_label',
                'payment_method_id',
            ]);
        });
    }
};
