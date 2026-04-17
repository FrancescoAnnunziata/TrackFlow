<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('client_hour', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hour_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        // Migrate existing data
        DB::table('hours')->whereNotNull('client_id')->orderBy('id')->each(function ($hour) {
            DB::table('client_hour')->insert([
                'hour_id' => $hour->id,
                'client_id' => $hour->client_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        Schema::table('hours', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropColumn('client_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hours', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->constrained();
        });

        // Restore first client from pivot
        DB::table('client_hour')
            ->select('hour_id', DB::raw('MIN(client_id) as client_id'))
            ->groupBy('hour_id')
            ->each(function ($pivot) {
                DB::table('hours')
                    ->where('id', $pivot->hour_id)
                    ->update(['client_id' => $pivot->client_id]);
            });

        Schema::dropIfExists('client_hour');
    }
};