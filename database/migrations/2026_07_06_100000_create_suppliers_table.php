<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fornitori: anagrafica speculare a quella dei clienti, popolata perlopiù
     * dall'import delle fatture passive da Fatture in Cloud.
     */
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('entity_type')->default('company');
            $table->string('vat_number')->nullable()->index();
            $table->string('tax_code')->nullable();
            $table->string('address_street')->nullable();
            $table->string('address_postal_code', 16)->nullable();
            $table->string('address_city')->nullable();
            $table->string('address_province', 8)->nullable();
            $table->string('country')->default('Italia');
            $table->string('country_iso', 2)->default('IT');
            $table->string('email')->nullable();
            $table->string('certified_email')->nullable();
            $table->string('ei_code', 16)->nullable();
            $table->string('fic_entity_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
