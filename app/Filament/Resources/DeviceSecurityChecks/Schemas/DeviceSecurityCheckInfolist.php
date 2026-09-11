<?php

namespace App\Filament\Resources\DeviceSecurityChecks\Schemas;

use App\Models\DeviceSecurityCheck;
use Closure;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Dettaglio di una rilevazione del censimento endpoint.
 *
 * I sei campi critici sono in cima e colorati in base allo stato calcolato
 * dalla rilevazione stessa (rosso = rischio, grigio = non rilevato), cosi' la
 * lettura parte da cosa non va senza dover scorrere i sessanta campi.
 *
 * Ogni campo ha un'icona "i" con spiegazione al passaggio del mouse (cosa
 * contiene, perche' conta): il testo viene da FIELD_EXPLANATIONS, distillato
 * dalla guida ai campi che accompagna lo script PowerShell.
 */
class DeviceSecurityCheckInfolist
{
    /**
     * Spiegazione breve per campo, mostrata nell'icona "i" al passaggio del
     * mouse. Chiave = nome colonna sul model.
     *
     * @var array<string, string>
     */
    private const FIELD_EXPLANATIONS = [
        // Rilevazione
        'checked_at' => 'Data e ora della rilevazione. Un censimento vecchio di mesi non e\' ne\' vero ne\' falso: e\' inutilizzabile.',
        'detected_by' => 'Utente Windows con cui e\' stato lanciato lo script: tracciabilita\' della raccolta.',
        'ran_as_admin' => 'Se NO, i campi su BitLocker, TPM, Defender e Secure Boot sono vuoti o inattendibili: quella macchina va rifatta con privilegi elevati.',
        'source' => 'Se la rilevazione arriva dallo script di censimento o e\' stata inserita a mano.',
        'hostname' => 'Nome del PC in rete. Non e\' la chiave stabile nel tempo: usa il Numero di serie, che non cambia se il PC viene rinominato o reinstallato.',

        // Sistema operativo e patch
        'os_name' => 'Nome del sistema operativo (es. Windows 11 Pro).',
        'os_edition' => 'Home non supporta BitLocker gestito, criteri di gruppo ed enrollment aziendale: una macchina Home in azienda e\' un problema strutturale, non configurabile.',
        'os_version' => 'Il "feature update" installato (22H2, 23H2, 24H2...). E\' questo, non il fatto che sia "Windows 11", a determinare se il sistema riceve ancora patch.',
        'os_build' => 'Build e revisione esatte, per confrontare con il bollettino Microsoft del mese.',
        'os_architecture' => '32 bit oggi indica hardware molto datato.',
        'os_installed_at' => 'Data di installazione: utile per stimare l\'eta\' reale della macchina e individuare reinstallazioni "artigianali".',
        'last_reboot_at' => 'Molte patch diventano efficaci solo dopo il riavvio: una macchina accesa da 90 giorni puo\' risultare "aggiornata" ed essere in realta\' vulnerabile.',
        'days_since_reboot' => 'Molte patch diventano efficaci solo dopo il riavvio: un valore alto puo\' significare patch scaricate ma non ancora attive davvero.',
        'last_patch_at' => 'Data dell\'ultimo aggiornamento Windows installato.',
        'last_patch_kb' => 'Codice dell\'aggiornamento (es. KB5039212), per il riscontro puntuale col bollettino Microsoft.',
        'days_since_last_patch' => 'Microsoft rilascia patch il secondo martedi\' di ogni mese: oltre i 45 giorni la macchina e\' indietro di almeno un ciclo.',
        'reboot_pending' => 'Se SI, gli aggiornamenti sono scaricati ma in attesa di riavvio: le patch ci sono ma non sono ancora attive.',

        // Antivirus
        'av_product' => 'Antivirus rilevato tramite le API di Defender.',
        'av_third_party' => 'Serve a scoprire prodotti dimenticati con licenza scaduta, o due antivirus installati insieme (tipicamente si neutralizzano a vicenda).',
        'av_realtime' => 'Un antivirus che scansiona solo su richiesta non protegge da nulla in pratica.',
        'av_service_active' => 'Distingue "installato" da "funzionante": il motore antimalware e\' davvero in esecuzione?',
        'av_signatures_updated_at' => 'Data delle definizioni antivirus installate.',
        'av_signatures_age_days' => 'Oltre i 3-4 giorni e\' un segnale che la macchina non comunica con l\'esterno o che il servizio e\' bloccato.',
        'av_last_scan_at' => 'Data dell\'ultima scansione rapida completata: indicatore di macchina viva e attiva.',

        // Cifratura e avvio
        'bitlocker_status' => 'FullyEncrypted / FullyDecrypted / EncryptionInProgress. Senza cifratura, chi estrae il disco legge tutto: in caso di smarrimento con dati personali e\' una violazione notificabile ai sensi del GDPR.',
        'bitlocker_method' => 'Algoritmo di cifratura (es. XtsAes128 / XtsAes256).',
        'bitlocker_protectors' => 'TPM, TPM+PIN, RecoveryPassword, Password. Il solo TPM sblocca in automatico all\'avvio: protegge dal furto del disco, non dal furto del portatile acceso o in sospensione.',
        'bitlocker_recovery_key_present' => 'Lo script verifica solo che una chiave di ripristino esista, mai il suo valore.',
        'bitlocker_key_location' => 'Dove e\' archiviata la chiave (Active Directory, account Microsoft, gestore password, foglio in cassaforte...). Cifratura senza custodia della chiave non e\' sicurezza: al primo aggiornamento firmware che la richiede, senza di essa il dato e\' perso per sempre.',
        'tpm_present' => 'Chip crittografico che custodisce le chiavi: prerequisito di BitLocker e requisito minimo di Windows 11.',
        'tpm_ready' => 'Chip crittografico che custodisce le chiavi: prerequisito di BitLocker e requisito minimo di Windows 11.',
        'secure_boot' => 'Impedisce il caricamento all\'avvio di codice non firmato (bootkit). Se disattivo o "BIOS legacy", la macchina e\' in modalita\' compatibilita\': BitLocker e altre protezioni sono limitate.',

        // Account e accessi
        'builtin_admin_name' => 'Nome dell\'account amministratore predefinito, identificato per SID: funziona anche se e\' stato rinominato o il sistema e\' in italiano.',
        'builtin_admin_status' => 'L\'account Administrator predefinito e\' il bersaglio noto a chiunque, su ogni Windows. Valore atteso: disabilitato.',
        'builtin_admin_renamed' => 'Se ha ancora il nome predefinito. Misura minore, ma a costo nullo.',
        'local_active_accounts' => 'Tutti gli account locali abilitati: serve a trovare utenze dimenticate (ex dipendenti, account ospite, account del fornitore).',
        'logged_user' => 'Utente al momento della rilevazione: riscontro veloce con l\'assegnatario del dispositivo.',
        'firewall_profiles' => 'Stato dei tre profili (Domain, Private, Public). Il profilo Public e\' quello attivo su reti non fidate (hotel, aeroporti, wifi cliente): deve essere attivo su tutti e tre.',
        'rdp_enabled' => 'Desktop remoto: uno dei vettori piu\' sfruttati per l\'accesso iniziale nelle PMI. Valore atteso: disattivo, salvo motivazione documentata.',
        'screen_lock_policy' => 'Se "nessuna policy di macchina", l\'impostazione e\' lasciata alla discrezione del singolo, non imposta centralmente.',
        'screen_timeout_ac_seconds' => 'Un valore alto o nullo suggerisce postazioni che restano sbloccate e accessibili.',

        // Gestione e software
        'azure_ad_joined' => 'Registrazione su Microsoft Entra ID (ex Azure AD): insieme a dominio e MDM definisce se esiste una gestione centralizzata da cui governare la macchina.',
        'domain_membership' => 'Appartenenza a un dominio Active Directory locale.',
        'mdm_enrolled' => 'Se esiste un sistema di gestione centralizzata (Intune o equivalente). Finche\' e\' negativo su tutto il parco, ogni dato di questo censimento e\' una fotografia manuale che invecchia dal momento in cui la scatti.',
        'mdm_url' => 'URL del sistema di gestione centralizzata, se presente.',
        'installed_software_count' => 'Uno scostamento marcato rispetto alle altre macchine segnala una postazione fuori standard.',
        'onedrive_status' => 'Indizio che i file siano sincronizzati altrove, non prova di backup: la sincronizzazione replica anche cancellazioni e cifratura da ransomware.',
        'remote_control_tools' => 'TeamViewer, AnyDesk, RustDesk e simili: possono essere shadow IT legittimo (spesso con licenza personale e password deboli) oppure residui di accessi non autorizzati. Vanno verificati uno per uno.',
        'critical_apps' => 'Le patch di Windows non toccano browser, Acrobat, Java, client VPN: e\' li\' che passa la maggior parte degli attacchi reali.',
        'ram_gb' => 'Indicatore indiretto di obsolescenza e di quali soluzioni di sicurezza la macchina regge.',
        'disks' => 'Un disco pieno blocca gli aggiornamenti di Windows: causa frequente di macchine ferme a patch vecchie.',

        // Backup
        'backup_type' => 'Cosa viene salvato, dove e con quale frequenza. Va accertato parlando con l\'utente: non e\' deducibile dal sistema.',
        'backup_last_ok_at' => 'Data dell\'ultimo backup completato con successo. "Backup configurato" da solo non dice nulla: un backup fallito da tre mesi resta comunque "configurato".',
        'backup_last_restore_test_at' => 'Data dell\'ultima prova di ripristino riuscita, da compilare a mano: il sistema non la conosce. Una prova vecchia di anni vale poco, perche\' nel frattempo sono cambiati dati, supporti e procedura.',
    ];

    /**
     * Spiegazione dei sei campi critici: cosa verifica il controllo in
     * generale, non il motivo della segnalazione specifica (quello e' nel
     * testo di aiuto sotto al badge). Chiave = la stessa di
     * DeviceSecurityCheck::CRITICAL_CHECKS.
     *
     * @var array<string, string>
     */
    private const CRITICAL_EXPLANATIONS = [
        'os_support' => 'Il sistema riceve ancora aggiornamenti di sicurezza? Distinzione centrale: aggiornato non e\' sinonimo di supportato. Un Windows 10 con tutte le patch installate e\' comunque fuori supporto e non ne ricevera\' altre. E\' il campo con la priorita\' piu\' alta di tutto il censimento.',
        'bitlocker' => 'Un disco puo\' risultare cifrato con la protezione sospesa (accade dopo aggiornamenti firmware): tecnicamente cifrato, di fatto apribile da chiunque estragga il disco.',
        'laps' => 'Senza LAPS, quasi sempre la stessa password amministrativa e\' impostata su tutti i PC: chi la recupera da una sola macchina ha accesso amministrativo all\'intero parco. E\' il meccanismo classico del movimento laterale.',
        'admin_group' => 'Se l\'utente quotidiano e\' amministratore, qualsiasi cosa apra (allegato, macro, installer) eredita quei privilegi. E\' il singolo controllo con il maggior rapporto beneficio/costo.',
        'av_tamper' => 'Impedisce che l\'antivirus venga disattivato da script, malware o dall\'utente stesso, anche con privilegi amministrativi. Il primo passo di quasi ogni ransomware e\' spegnere l\'antivirus: senza questa protezione ci riesce in una riga di comando.',
        'backup_restore' => 'Un backup esiste solo nel momento in cui lo si e\' ripristinato almeno una volta; prima di allora e\' un\'ipotesi. Nella maggior parte dei casi di ransomware in cui il recupero fallisce, il backup c\'era.',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Rilevazione')
                    ->columns(4)
                    ->components([
                        TextEntry::make('device.asset_code')->label('Codice asset'),
                        self::field('hostname')->label('Hostname')->placeholder('—'),
                        self::field('checked_at')->label('Data rilevazione')->dateTime('d/m/Y H:i'),
                        self::field('detected_by')->label('Rilevato da')->placeholder('—'),
                        TextEntry::make('outcome')->label('Esito')->badge(),
                        TextEntry::make('risk_level')->label('Rischio')->badge(),
                        self::field('ran_as_admin')
                            ->label('Eseguito come admin')
                            ->badge()
                            ->formatStateUsing(fn (?bool $state): string => self::yesNo($state))
                            ->color(fn (?bool $state): string => $state === true ? 'success' : 'warning'),
                        self::field('source')
                            ->label('Origine')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state === DeviceSecurityCheck::SOURCE_INVENTORY
                                ? 'Censimento CSV'
                                : 'Verifica manuale')
                            ->color('gray'),
                    ]),

                Section::make('Campi critici')
                    ->description('Rosso: valore in stato di rischio. Grigio: non rilevato dallo script.')
                    ->columns(3)
                    ->visible(fn (DeviceSecurityCheck $record): bool => $record->isFromInventory())
                    ->components([
                        self::critical('os_support', 'os_support', 'Supporto sistema operativo'),
                        self::critical('bitlocker', 'bitlocker_protection', 'Protezione BitLocker'),
                        self::critical('laps', 'laps', 'LAPS'),
                        self::critical('admin_group', 'admin_group_members', 'Amministratori locali')
                            ->columnSpan(2),
                        self::critical('av_tamper', 'av_tamper_protection', 'Tamper Protection', fn (bool $value): string => self::yesNo($value)),
                        self::critical('backup_restore', 'backup_last_restore_test_at', 'Ultimo restore testato', fn ($value): string => $value->format('d/m/Y')),
                    ]),

                Section::make('Sistema operativo e patch')
                    ->columns(4)
                    ->collapsible()
                    ->components([
                        self::field('os_name')->label('Sistema operativo')->placeholder('—'),
                        self::field('os_edition')->label('Edizione')->placeholder('—'),
                        self::field('os_version')->label('Versione')->placeholder('—'),
                        self::field('os_build')->label('Build')->placeholder('—'),
                        self::field('os_architecture')->label('Architettura')->placeholder('—'),
                        self::field('os_installed_at')->label('Installato il')->date('d/m/Y')->placeholder('—'),
                        self::field('last_reboot_at')->label('Ultimo riavvio')->dateTime('d/m/Y H:i')->placeholder('—'),
                        self::field('days_since_reboot')->label('Giorni da riavvio')->placeholder('—'),
                        self::field('last_patch_at')->label('Ultima patch')->date('d/m/Y')->placeholder('—'),
                        self::field('last_patch_kb')->label('KB')->placeholder('—'),
                        self::field('days_since_last_patch')
                            ->label('Giorni da ultima patch')
                            ->badge()
                            ->color(fn (?int $state): string => self::daysColor($state, 'max_days_since_patch'))
                            ->placeholder('—'),
                        self::field('reboot_pending')
                            ->label('Riavvio pendente')
                            ->formatStateUsing(fn (?bool $state): string => self::yesNo($state)),
                    ]),

                Section::make('Antivirus')
                    ->columns(4)
                    ->collapsible()
                    ->components([
                        self::field('av_product')->label('Prodotto')->placeholder('—'),
                        self::field('av_third_party')->label('Terze parti')->placeholder('—'),
                        self::field('av_realtime')->label('Real time')->formatStateUsing(fn (?bool $s): string => self::yesNo($s)),
                        self::field('av_service_active')->label('Servizio attivo')->formatStateUsing(fn (?bool $s): string => self::yesNo($s)),
                        self::field('av_signatures_updated_at')->label('Firme aggiornate al')->date('d/m/Y')->placeholder('—'),
                        self::field('av_signatures_age_days')
                            ->label('Eta firme (giorni)')
                            ->badge()
                            ->color(fn (?int $state): string => self::daysColor($state, 'max_av_signature_age_days'))
                            ->placeholder('—'),
                        self::field('av_last_scan_at')->label('Ultima scansione')->date('d/m/Y')->placeholder('—'),
                    ]),

                Section::make('Cifratura e avvio')
                    ->columns(4)
                    ->collapsible()
                    ->components([
                        self::field('bitlocker_status')->label('Stato BitLocker')->placeholder('—'),
                        self::field('bitlocker_method')->label('Metodo')->placeholder('—'),
                        self::field('bitlocker_protectors')->label('Protettori')->placeholder('—'),
                        self::field('bitlocker_recovery_key_present')
                            ->label('Recovery key')
                            ->formatStateUsing(fn (?bool $s): string => self::yesNo($s)),
                        self::field('bitlocker_key_location')->label('Dove e custodita la chiave')->placeholder('Non indicato'),
                        self::field('tpm_present')->label('TPM presente')->formatStateUsing(fn (?bool $s): string => self::yesNo($s)),
                        self::field('tpm_ready')->label('TPM pronto')->formatStateUsing(fn (?bool $s): string => self::yesNo($s)),
                        self::field('secure_boot')->label('Secure Boot')->formatStateUsing(fn (?bool $s): string => self::yesNo($s)),
                    ]),

                Section::make('Account e accessi')
                    ->columns(3)
                    ->collapsible()
                    ->components([
                        self::field('builtin_admin_name')->label('Admin builtin')->placeholder('—'),
                        self::field('builtin_admin_status')->label('Stato admin builtin')->placeholder('—'),
                        self::field('builtin_admin_renamed')->label('Rinominato')->formatStateUsing(fn (?bool $s): string => self::yesNo($s)),
                        self::field('local_active_accounts')->label('Account locali attivi')->placeholder('—')->columnSpan(2),
                        self::field('logged_user')->label('Utente loggato')->placeholder('—'),
                        self::field('firewall_profiles')->label('Firewall')->placeholder('—')->columnSpan(2),
                        self::field('rdp_enabled')->label('RDP')->formatStateUsing(fn (?bool $s): string => self::yesNo($s)),
                        self::field('screen_lock_policy')->label('Blocco schermo')->placeholder('—')->columnSpan(2),
                        self::field('screen_timeout_ac_seconds')->label('Timeout video (s)')->placeholder('—'),
                    ]),

                Section::make('Gestione e software')
                    ->columns(4)
                    ->collapsible()
                    ->components([
                        self::field('azure_ad_joined')->label('Azure AD')->formatStateUsing(fn (?bool $s): string => self::yesNo($s)),
                        self::field('domain_membership')->label('In dominio')->placeholder('—'),
                        self::field('mdm_enrolled')->label('MDM')->formatStateUsing(fn (?bool $s): string => self::yesNo($s)),
                        self::field('mdm_url')->label('URL MDM')->placeholder('—'),
                        self::field('installed_software_count')->label('Software installati')->placeholder('—'),
                        self::field('onedrive_status')->label('OneDrive')->placeholder('—'),
                        self::field('remote_control_tools')
                            ->label('Strumenti di controllo remoto')
                            ->placeholder('Nessuno')
                            ->color(fn (?string $state): ?string => filled($state) ? 'warning' : null)
                            ->columnSpan(2),
                        self::field('critical_apps')->label('App critiche')->placeholder('—')->columnSpanFull(),
                        self::field('ram_gb')->label('RAM (GB)')->placeholder('—'),
                        self::field('disks')->label('Dischi')->placeholder('—')->columnSpan(3),
                    ]),

                Section::make('Backup')
                    ->columns(3)
                    ->collapsible()
                    ->components([
                        self::field('backup_type')->label('Tipo')->placeholder('Non indicato'),
                        self::field('backup_last_ok_at')->label('Ultimo backup OK')->date('d/m/Y')->placeholder('—'),
                        self::field('backup_last_restore_test_at')->label('Ultimo restore testato')->date('d/m/Y')->placeholder('Mai'),
                    ]),

                Section::make('Note')
                    ->collapsible()
                    ->components([
                        TextEntry::make('notes')->label('')->placeholder('—')->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * TextEntry con l'icona "i" e spiegazione al passaggio del mouse, presa
     * da FIELD_EXPLANATIONS. Se il campo non ha una spiegazione mappata,
     * l'icona semplicemente non compare (nessun errore).
     */
    private static function field(string $column): TextEntry
    {
        $entry = TextEntry::make($column);

        $explanation = self::FIELD_EXPLANATIONS[$column] ?? null;

        return $explanation === null
            ? $entry
            : $entry->hintIcon(Heroicon::OutlinedInformationCircle, $explanation);
    }

    /**
     * Voce di un campo critico: colore e icona vengono dallo stato calcolato
     * dalla rilevazione, il testo di aiuto riporta il motivo della segnalazione,
     * l'icona "i" spiega cosa verifica il controllo in generale.
     *
     * Il testo del badge NON puo' basarsi su placeholder()+formatStateUsing():
     * Filament controlla se il valore grezzo della colonna e' vuoto PRIMA di
     * chiamare il formatter, quindi quando il rischio si manifesta proprio
     * come valore assente (bitlocker_protection vuoto, nessun restore mai
     * testato) il placeholder generico vincerebbe sempre, mostrando "Non
     * rilevato" in grigio-sembrante sopra un badge colorato di rosso — due
     * segnali in contraddizione. Si usa quindi state() per decidere sempre il
     * testo a partire dallo stato valutato, non dal valore grezzo.
     */
    private static function critical(string $key, string $column, string $label, ?Closure $format = null): TextEntry
    {
        return TextEntry::make($column)
            ->label($label)
            ->badge()
            ->hintIcon(Heroicon::OutlinedInformationCircle, self::CRITICAL_EXPLANATIONS[$key] ?? null)
            ->state(fn (DeviceSecurityCheck $record): string => self::criticalBadgeText($key, $record, $column, $format))
            ->color(fn (DeviceSecurityCheck $record): string => self::stateColor($record->criticalState($key)))
            ->icon(fn (DeviceSecurityCheck $record): ?Heroicon => match ($record->criticalState($key)) {
                DeviceSecurityCheck::STATE_RISK => Heroicon::OutlinedExclamationTriangle,
                DeviceSecurityCheck::STATE_OK => Heroicon::OutlinedCheckCircle,
                default => Heroicon::OutlinedQuestionMarkCircle,
            })
            ->helperText(fn (DeviceSecurityCheck $record): ?string => $record->evaluateCritical($key)['state'] === DeviceSecurityCheck::STATE_RISK
                ? $record->evaluateCritical($key)['detail']
                : null);
    }

    /**
     * Testo da mostrare per un campo critico: il valore grezzo formattato se
     * presente, altrimenti un testo derivato dallo stato valutato — mai il
     * placeholder generico di Filament, che ignorerebbe lo stato di rischio
     * quando il rischio e' proprio l'assenza del valore (vedi critical()).
     * Riusato dalla tabella (DeviceSecurityChecksTable) per lo stesso motivo.
     */
    public static function criticalBadgeText(string $key, DeviceSecurityCheck $record, string $column, ?Closure $format = null): string
    {
        $format ??= fn (mixed $value): string => (string) $value;
        $value = $record->{$column};

        if ($value !== null) {
            return $format($value);
        }

        $evaluation = $record->evaluateCritical($key);

        return match ($evaluation['state']) {
            DeviceSecurityCheck::STATE_RISK => $evaluation['detail'] ?? 'A rischio',
            DeviceSecurityCheck::STATE_OK => 'A posto',
            default => 'Non rilevato',
        };
    }

    public static function stateColor(string $state): string
    {
        return match ($state) {
            DeviceSecurityCheck::STATE_RISK => 'danger',
            DeviceSecurityCheck::STATE_OK => 'success',
            default => 'gray',
        };
    }

    /** Spiegazione di un campo critico, riusata da EndpointHistory/blade per il riepilogo sulla scheda dispositivo. */
    public static function criticalExplanation(string $key): ?string
    {
        return self::CRITICAL_EXPLANATIONS[$key] ?? null;
    }

    private static function yesNo(?bool $state): string
    {
        return match ($state) {
            true => 'SI',
            false => 'NO',
            default => 'N/D',
        };
    }

    /** Colora un conteggio di giorni rispetto alla soglia di configurazione. */
    private static function daysColor(?int $days, string $threshold): string
    {
        if ($days === null) {
            return 'gray';
        }

        return $days <= (int) config("inventario_endpoint.thresholds.{$threshold}") ? 'success' : 'danger';
    }
}
