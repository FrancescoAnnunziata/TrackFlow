<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            // La FK su client_id usava l'unique (client_id, number) come indice:
            // gli diamo un indice dedicato prima di sostituire l'unique.
            $table->index('client_id');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            // Fatture e note di credito condividono la tabella e possono avere lo
            // stesso numero per lo stesso cliente (numerazioni separate su FIC):
            // l'unicità va qualificata anche per tipo.
            $table->dropUnique(['client_id', 'number']);
            $table->unique(['client_id', 'number', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique(['client_id', 'number', 'type']);
            $table->unique(['client_id', 'number']);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex(['client_id']);
        });
    }
};
