<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('costi', function (Blueprint $table): void {
            // Giustificativi del costo (es. PDF della busta paga per i compensi).
            $table->json('attachments')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('costi', function (Blueprint $table): void {
            $table->dropColumn('attachments');
        });
    }
};
