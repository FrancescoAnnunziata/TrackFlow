<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chi emette i documenti: G8 Labs S.r.l. e la P.IVA personale di Giorgio.
 *
 * Fino a qui TrackFlow lo deduceva dallo strumento di fatturazione del cliente
 * (`clients.invoicing_provider`: «fiscozen» = P.IVA personale). Col passaggio
 * della P.IVA ad A-Cube quell'equivalenza si rompe, quindi le due domande si
 * separano: l'emittente dice chi fattura, il canale dice con cosa si trasmette.
 *
 * Le intestazioni stavano in config/azienda.php, alimentate dal .env: qui si
 * copiano una volta in tabella. Da quel momento si modificano dalla pagina
 * Emittenti e le variabili G8LABS_* / GIORGIO_* si possono togliere dal .env.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emittenti', function (Blueprint $table) {
            $table->id();
            $table->string('chiave', 32)->unique();
            $table->string('nome');
            $table->string('sottotitolo')->nullable();
            $table->string('partita_iva', 32)->nullable();
            $table->string('codice_fiscale', 32)->nullable();
            $table->string('indirizzo')->nullable();
            $table->string('cap', 16)->nullable();
            $table->string('citta')->nullable();
            $table->string('provincia', 8)->nullable();
            $table->string('email')->nullable();
            $table->string('telefono', 64)->nullable();
            $table->string('pec')->nullable();
            $table->string('iban', 64)->nullable();
            $table->string('logo')->nullable();
            $table->text('nota_fiscale')->nullable();
            // Strumento con cui partono oggi le sue fatture.
            $table->string('canale_invio', 32);
            // Regime degli anni senza eccezione in emittente_regimi.
            $table->string('regime_fiscale', 8);
            // La società: la sua dashboard, i conti e le passive di default.
            $table->boolean('principale')->default(false);
            $table->timestamps();
        });

        Schema::create('emittente_regimi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emittente_id')->constrained('emittenti')->cascadeOnDelete();
            $table->unsignedSmallInteger('anno');
            $table->string('regime_fiscale', 8);
            $table->timestamps();
            $table->unique(['emittente_id', 'anno']);
        });

        $now = now();
        $g8 = DB::table('emittenti')->insertGetId($this->intestazione('g8labs', [
            'nome_default' => 'g8labs srl unipersonale',
            'logo_default' => 'images/LogoBlack.png',
        ]) + [
            'canale_invio' => 'fatture_in_cloud',
            'regime_fiscale' => 'RF01',
            'principale' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $giorgio = DB::table('emittenti')->insertGetId($this->intestazione('giorgio', [
            'nome_default' => 'Giorgio Giotto',
        ]) + [
            'canale_invio' => 'fiscozen',
            'regime_fiscale' => 'RF19',
            'principale' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        // La P.IVA personale è stata in ordinario nel 2025.
        DB::table('emittente_regimi')->insert([
            'emittente_id' => $giorgio, 'anno' => 2025, 'regime_fiscale' => 'RF01',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // Clienti: l'emittente sostituisce invoicing_provider.
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('emittente_id')->nullable()->after('invoicing_provider')->constrained('emittenti');
        });
        DB::table('clients')->where('invoicing_provider', 'fiscozen')->update(['emittente_id' => $giorgio]);
        DB::table('clients')->whereNull('emittente_id')->update(['emittente_id' => $g8]);
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('invoicing_provider');
        });

        // Fatture: emittente, canale e regime copiati e fissi.
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('emittente_id')->nullable()->after('client_id')->constrained('emittenti');
            $table->string('canale', 32)->nullable()->after('emittente_id');
            $table->string('regime_fiscale', 8)->nullable()->after('canale');
        });
        DB::table('invoices')->update([
            'emittente_id' => DB::raw('(SELECT emittente_id FROM clients WHERE clients.id = invoices.client_id)'),
        ]);
        DB::table('invoices')->where(fn ($q) => $q->whereNotNull('fic_document_id')->orWhereNotNull('fic_sent_at'))
            ->update(['canale' => 'fatture_in_cloud']);
        DB::table('invoices')->whereNull('canale')->where('emittente_id', $giorgio)->update(['canale' => 'fiscozen']);
        DB::table('invoices')->whereNull('canale')->update(['canale' => 'manuale']);
        DB::table('invoices')->where('emittente_id', $g8)->update(['regime_fiscale' => 'RF01']);
        DB::table('invoices')->where('emittente_id', $giorgio)->whereYear('issue_date', 2025)->update(['regime_fiscale' => 'RF01']);
        DB::table('invoices')->where('emittente_id', $giorgio)->whereNull('regime_fiscale')->update(['regime_fiscale' => 'RF19']);

        // Preventivi: la chiave testuale diventa una relazione.
        Schema::table('quotes', function (Blueprint $table) {
            $table->foreignId('emittente_id')->nullable()->after('issuer_key')->constrained('emittenti');
        });
        DB::table('quotes')->where('issuer_key', 'giorgio')->update(['emittente_id' => $giorgio]);
        DB::table('quotes')->whereNull('emittente_id')->update(['emittente_id' => $g8]);
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('issuer_key');
        });

        // Conti e fatture passive: oggi sono tutti di G8 Labs.
        foreach (['bank_accounts', 'passive_invoices'] as $tabella) {
            Schema::table($tabella, function (Blueprint $table) {
                $table->foreignId('emittente_id')->nullable()->after('id')->constrained('emittenti');
            });
            DB::table($tabella)->update(['emittente_id' => $g8]);
        }

        // Da qui in avanti il valore è obbligatorio.
        foreach (['clients', 'invoices', 'quotes', 'bank_accounts', 'passive_invoices'] as $tabella) {
            Schema::table($tabella, function (Blueprint $table) {
                $table->foreignId('emittente_id')->nullable(false)->change();
            });
        }
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('canale', 32)->nullable(false)->change();
            $table->string('regime_fiscale', 8)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        $giorgio = DB::table('emittenti')->where('chiave', 'giorgio')->value('id');

        Schema::table('clients', function (Blueprint $table) {
            $table->string('invoicing_provider')->default('fatture_in_cloud')->after('ei_code');
        });
        DB::table('clients')->where('emittente_id', $giorgio)->update(['invoicing_provider' => 'fiscozen']);

        Schema::table('quotes', function (Blueprint $table) {
            $table->string('issuer_key', 32)->nullable();
        });
        DB::table('quotes')->where('emittente_id', $giorgio)->update(['issuer_key' => 'giorgio']);

        foreach (['clients', 'invoices', 'quotes', 'bank_accounts', 'passive_invoices'] as $tabella) {
            Schema::table($tabella, function (Blueprint $table) {
                $table->dropConstrainedForeignId('emittente_id');
            });
        }
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['canale', 'regime_fiscale']);
        });

        Schema::dropIfExists('emittente_regimi');
        Schema::dropIfExists('emittenti');
    }

    /**
     * Intestazione letta da config/azienda.php com'era prima di questa
     * migrazione, con il .env come riserva (config in cache o chiave assente).
     *
     * @param  array{nome_default: string, logo_default?: string}  $default
     * @return array<string, string|null>
     */
    private function intestazione(string $chiave, array $default): array
    {
        $config = (array) config("azienda.emittenti.{$chiave}", []);
        $prefisso = strtoupper($chiave === 'g8labs' ? 'G8LABS' : 'GIORGIO');
        $campo = function (string $nome) use ($config, $prefisso): ?string {
            $valore = $config[$nome] ?? env($prefisso.'_'.strtoupper($nome));

            return filled($valore) ? trim((string) $valore) : null;
        };

        return [
            'chiave' => $chiave,
            'nome' => $campo('nome') ?? $default['nome_default'],
            'sottotitolo' => $campo('sottotitolo'),
            'partita_iva' => $campo('partita_iva'),
            'codice_fiscale' => $campo('codice_fiscale'),
            'indirizzo' => $campo('indirizzo'),
            'cap' => $campo('cap'),
            'citta' => $campo('citta'),
            'provincia' => $campo('provincia'),
            'email' => $campo('email'),
            'telefono' => $campo('telefono'),
            'pec' => $campo('pec'),
            'iban' => $campo('iban'),
            'logo' => $campo('logo') ?? ($default['logo_default'] ?? null),
            'nota_fiscale' => $campo('nota_fiscale'),
        ];
    }
};
