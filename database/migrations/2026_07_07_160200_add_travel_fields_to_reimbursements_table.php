<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dettaglio del tragitto per i rimborsi di tipo trasferta: snapshot di
     * DA/A/oggetto/km (dalla tabella Mapping al momento della generazione), cosi'
     * la nota spese resta coerente anche se la tabella viene poi modificata.
     */
    public function up(): void
    {
        Schema::table('reimbursements', function (Blueprint $table) {
            $table->string('travel_type')->nullable();
            $table->string('from_location')->nullable();
            $table->string('to_location')->nullable();
            $table->string('purpose')->nullable();
            $table->decimal('km', 8, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reimbursements', function (Blueprint $table) {
            $table->dropColumn(['travel_type', 'from_location', 'to_location', 'purpose', 'km']);
        });
    }
};
