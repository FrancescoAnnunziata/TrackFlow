<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hours', function (Blueprint $table) {
            $table->unsignedSmallInteger('minutes')->after('date')->default(0);
        });

        DB::table('hours')->orderBy('id')->each(function ($row) {
            $str = number_format((float) $row->hours, 1, '.', '');
            [$h, $d] = explode('.', $str);

            DB::table('hours')->where('id', $row->id)->update([
                'minutes' => ((int) $h) * 60 + ((int) $d) * 10,
            ]);
        });

        Schema::table('hours', function (Blueprint $table) {
            $table->dropColumn('hours');
        });
    }

    public function down(): void
    {
        Schema::table('hours', function (Blueprint $table) {
            $table->decimal('hours', 3, 1)->after('date')->default(0);
        });

        DB::table('hours')->orderBy('id')->each(function ($row) {
            $minutes = (int) $row->minutes;
            $h = intdiv($minutes, 60);
            $d = (int) round(($minutes % 60) / 10);

            DB::table('hours')->where('id', $row->id)->update([
                'hours' => $h + $d / 10,
            ]);
        });

        Schema::table('hours', function (Blueprint $table) {
            $table->dropColumn('minutes');
        });
    }
};