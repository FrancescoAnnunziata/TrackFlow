<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('asset_code');
            $table->string('barcode')->nullable();
            $table->uuid('qr_token')->unique();
            $table->string('name');
            $table->string('category')->default('Other');
            $table->string('type')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->string('supplier')->nullable();
            $table->string('invoice_number')->nullable();
            $table->date('warranty_until')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('location')->nullable();
            $table->string('status')->default('in_stock');
            $table->text('notes')->nullable();
            $table->date('next_maintenance_at')->nullable();
            $table->json('attachments')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['client_id', 'asset_code']);
            $table->index('status');
            $table->index('category');
            $table->index('assigned_user_id');
            $table->index('serial_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
