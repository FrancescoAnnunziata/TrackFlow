<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('entity_type')->default('company')->after('name');
            $table->string('vat_number')->nullable()->after('entity_type');
            $table->string('tax_code')->nullable()->after('vat_number');
            $table->string('address_street')->nullable()->after('tax_code');
            $table->string('address_postal_code', 16)->nullable()->after('address_street');
            $table->string('address_city')->nullable()->after('address_postal_code');
            $table->string('address_province', 8)->nullable()->after('address_city');
            $table->string('country')->default('Italia')->after('address_province');
            $table->string('country_iso', 2)->default('IT')->after('country');
            $table->string('email')->nullable()->after('country_iso');
            $table->string('certified_email')->nullable()->after('email');
            $table->string('ei_code', 16)->nullable()->after('certified_email');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'entity_type',
                'vat_number',
                'tax_code',
                'address_street',
                'address_postal_code',
                'address_city',
                'address_province',
                'country',
                'country_iso',
                'email',
                'certified_email',
                'ei_code',
            ]);
        });
    }
};
