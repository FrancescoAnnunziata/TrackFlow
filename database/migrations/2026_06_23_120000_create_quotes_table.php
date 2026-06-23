<?php

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Client::class)->constrained()->restrictOnDelete();
            $table->string('number');
            $table->date('issue_date');
            $table->text('description')->nullable();
            $table->decimal('estimated_hours', 5, 1);
            $table->decimal('hourly_rate', 10, 2);
            $table->decimal('vat_rate', 5, 2)->default(22);
            // draft -> sent -> accepted | rejected, poi accepted -> invoiced
            $table->string('status')->default('draft');
            // Quando è stato inviato al cliente (base per i solleciti di approvazione).
            $table->timestamp('sent_at')->nullable();
            // Quanti solleciti sono già stati inviati (0 = nessuno, 1 = 5gg, 2 = 10gg).
            $table->unsignedTinyInteger('reminders_sent')->default(0);
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
