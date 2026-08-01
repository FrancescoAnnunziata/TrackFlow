<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabelle per l'assistente AI: una conversazione (thread) e i suoi messaggi.
 * La storia della chat vive qui (non in sessione): il job la ricostruisce dai
 * messaggi non-pending per rimandarla al modello.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_threads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->string('model')->nullable();
            $table->timestamps();
        });

        Schema::create('assistant_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assistant_thread_id')->constrained()->cascadeOnDelete();
            $table->string('role'); // user | assistant
            $table->longText('content')->nullable();
            $table->string('status')->default('done'); // pending | done | failed
            $table->json('steps')->nullable();   // strumenti usati (chip UI)
            $table->json('actions')->nullable(); // proposte di riconciliazione da confermare
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_threads');
    }
};
