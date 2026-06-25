<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('maintenance_date');
            $table->string('type')->default('ordinary');
            $table->text('description')->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->string('supplier')->nullable();
            $table->date('next_maintenance_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('device_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_maintenances');
    }
};
