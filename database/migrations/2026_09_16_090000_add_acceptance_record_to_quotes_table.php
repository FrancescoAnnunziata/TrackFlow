<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            // Come è arrivata l'accettazione: firma online dal documento, oppure
            // email / a voce / su carta registrate a mano dall'admin. I preventivi
            // già firmati restano senza valore e lo deducono da signature_path.
            $table->string('acceptance_method')->nullable()->after('signature_user_agent');
            // Chi ha comunicato l'accettazione, quando l'ha fatto e con che ruolo:
            // per un'accettazione registrata a mano è l'unica traccia del firmatario.
            $table->string('acceptance_author')->nullable()->after('acceptance_method');
            $table->string('acceptance_author_role')->nullable()->after('acceptance_author');
            // L'admin che l'ha registrata in TrackFlow, e quando: distingue la
            // registrazione dall'accettazione vera, che è avvenuta prima e altrove.
            $table->foreignId('acceptance_recorded_by')->nullable()->after('acceptance_author_role')->constrained('users')->nullOnDelete();
            $table->timestamp('acceptance_recorded_at')->nullable()->after('acceptance_recorded_by');
            // La prova conservata (l'email salvata in PDF) e una nota libera.
            $table->string('acceptance_evidence_path')->nullable()->after('acceptance_recorded_at');
            $table->text('acceptance_note')->nullable()->after('acceptance_evidence_path');
            // La scansione del documento firmato su carta: quando c'è, è lei la
            // copia che fa fede e sostituisce il PDF generato.
            $table->string('signed_copy_path')->nullable()->after('acceptance_note');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acceptance_recorded_by');
            $table->dropColumn([
                'acceptance_method',
                'acceptance_author',
                'acceptance_author_role',
                'acceptance_recorded_at',
                'acceptance_evidence_path',
                'acceptance_note',
                'signed_copy_path',
            ]);
        });
    }
};
