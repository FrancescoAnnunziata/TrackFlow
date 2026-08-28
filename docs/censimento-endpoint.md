# Censimento endpoint (CSV PowerShell)

Lo script di censimento produce un CSV — delimitatore `;`, UTF-8 con BOM, 68 colonne,
una riga per dispositivo ad ogni giro — che TrackFlow importa nella sezione
**Asset Management → Security check**.

## Struttura dati

L'import lavora su due livelli, come richiesto dallo storico:

| Livello | Tabella | Comportamento |
| --- | --- | --- |
| Dispositivo (anagrafica stabile) | `devices` | aggiornato ad ogni import, mai duplicato |
| Rilevazione (campi tecnici datati) | `device_security_checks` | ogni import aggiunge righe, non sovrascrive le precedenti |

Il **Seriale** e' la chiave di riconoscimento nel tempo, perche' l'hostname puo'
cambiare se il PC viene rinominato o reinstallato. Quando il BIOS espone un
segnaposto (`Default string`, `To Be Filled By O.E.M.`, … — elenco in
`config/inventario_endpoint.php`) il seriale vale come assente e il
riconoscimento passa dall'hostname.

Ricaricare lo stesso file non duplica nulla: una rilevazione con stesso
dispositivo e stessa `DataRilevazione` viene aggiornata anziche' ricreata.

## Mappatura delle colonne

### Anagrafica → `devices`

| Colonna CSV | Campo | Note |
| --- | --- | --- |
| `Hostname` | `hostname` | anche sulla rilevazione, per vedere i rename |
| `Produttore` | `manufacturer` | |
| `Modello` | `model` | |
| `Seriale` | `serial_number` | vuoto se e' un segnaposto del BIOS |
| `Assegnatario` | `inventory_assignee` | testo libero; se il nome corrisponde a un utente del cliente popola anche `assigned_user_id` |
| `Reparto` | `department` | |
| `Ubicazione` | `location` | |
| `StatoCicloVita` | `lifecycle_stage` | i valori noti ("in uso", "magazzino", "dismesso"…) aggiornano anche `status` |
| `Note` | `notes` | |

I campi manuali sovrascrivono solo se compilati: lasciarli vuoti nello script
non cancella quanto inserito dalla webapp.

### Rilevazione → `device_security_checks`

| Gruppo | Colonne CSV | Campi |
| --- | --- | --- |
| Metadati | `DataRilevazione`, `RilevatoDa`, `EseguitoComeAdmin` | `checked_at`, `detected_by`, `ran_as_admin` |
| Identita' | `Hostname`, `RAM_GB`, `Dischi`, `UtenteLoggato`, `UltimoUtilizzoProfilo` | `hostname`, `ram_gb`, `disks`, `logged_user`, `profile_last_used_at` |
| Sistema operativo | `OS`, `OS_Edizione`, `OS_Versione`, `OS_Build`, `OS_Architettura`, `OS_Supporto`, `OS_DataInstallazione`, `UltimoRiavvio`, `GiorniDaRiavvio` | `os_name`, `os_edition`, `os_version`, `os_build`, `os_architecture`, `os_support`, `os_installed_at`, `last_reboot_at`, `days_since_reboot` |
| Patch | `UltimaPatch_Data`, `UltimaPatch_KB`, `GiorniDaUltimaPatch`, `RiavvioPendente` | `last_patch_at`, `last_patch_kb`, `days_since_last_patch`, `reboot_pending` |
| Antivirus | `AV_*` | `av_product`, `av_third_party`, `av_realtime`, `av_service_active`, `av_signatures_updated_at`, `av_signatures_age_days`, `av_tamper_protection`, `av_last_scan_at` |
| Cifratura | `BitLocker_*`, `TPM_*`, `SecureBoot` | `bitlocker_status`, `bitlocker_protection`, `bitlocker_method`, `bitlocker_protectors`, `bitlocker_recovery_key_present`, `bitlocker_key_location`, `tpm_present`, `tpm_ready`, `secure_boot` |
| Account | `MembriGruppoAdmin`, `AdminBuiltin_*`, `LAPS`, `AccountLocaliAttivi` | `admin_group_members`, `builtin_admin_name`, `builtin_admin_status`, `builtin_admin_renamed`, `laps`, `local_active_accounts` |
| Rete | `Firewall`, `RDP`, `BloccoSchermo`, `TimeoutVideoAC_sec` | `firewall_profiles`, `rdp_enabled`, `screen_lock_policy`, `screen_timeout_ac_seconds` |
| Gestione | `AzureAD_Joined`, `Dominio_Joined`, `InDominio`, `MDM_Enrolled`, `MDM_Url` | `azure_ad_joined`, `domain_joined`, `domain_membership`, `mdm_enrolled`, `mdm_url` |
| Software | `NumeroSoftwareInstallati`, `AppCritiche`, `StrumentiControlloRemoto`, `OneDrive` | `installed_software_count`, `critical_apps`, `remote_control_tools`, `onedrive_status` |
| Backup | `Backup_Tipo`, `Backup_UltimoOK`, `Backup_UltimoRestoreTestato` | `backup_type`, `backup_last_ok_at`, `backup_last_restore_test_at` |

La riga CSV completa finisce anche in `raw_row` (JSON): le colonne che lo script
dovesse aggiungere in futuro non vanno perse in attesa di una colonna dedicata.

### Tre stati, non due

I booleani sono **nullable**: il CSV distingue `SI`, `NO` e `N/D` /
`N/D (serve admin)`. Perdere la differenza fra "no" e "non rilevabile"
produrrebbe segnalazioni inventate. Dove il valore porta con se' una
motivazione (`NO (workgroup)`, `NO - password admin locale non gestita`) si
conserva la stringa originale.

## Campi critici e segnalazione visiva

Sei campi vengono valutati singolarmente ad ogni rilevazione
(`DeviceSecurityCheck::CRITICAL_CHECKS`). Stato `ok`, `risk` o `unknown`:

> **Nota su `admin_group`**: il dipendente che e' amministratore del proprio PC
> non fa scattare da solo la segnalazione — e' un caso troppo diffuso e non e'
> l'anomalia che questo controllo cerca. Resta pero' un problema di sicurezza
> a se stante (un utente admin puo' disattivare l'antivirus, installare
> qualunque software, ignorare le policy), da valutare come iniziativa a
> parte; il gruppo completo resta comunque leggibile nel dettaglio della
> rilevazione.

| Chiave | Rischio quando |
| --- | --- |
| `os_support` | `OS_Supporto` non e' "Supportato fino al …", oppure quella data e' passata |
| `admin_group` | `MembriGruppoAdmin` contiene account fuori dall'allowlist IT (`config/inventario_endpoint.php`) *e* diversi dall'utente loggato in quella rilevazione (`UtenteLoggato`) |
| `laps` | `LAPS` non inizia per "SI" (es. "NO - password admin locale non gestita") |
| `bitlocker` | `BitLocker_Protezione` diverso da "On" (se lo script non girava come admin resta `unknown`) |
| `av_tamper` | `AV_TamperProtection` = NO |
| `backup_restore` | `Backup_UltimoRestoreTestato` vuoto |

Dove si vedono:

- **Security check → tabella**: colonna *Criticità* (badge rosso col numero e
  tooltip con l'elenco), *Supporto SO*, *BitLocker*, *LAPS* colorate per stato.
- **Security check → dettaglio**: sezione *Campi critici* in cima, con icona,
  colore e il motivo della segnalazione.
- **Dispositivo → scheda**: sezione *Sicurezza endpoint* con lo stato attuale e
  da quante rilevazioni consecutive dura.
- **Dispositivi → tabella**: la colonna *Security* elenca sotto l'esito i campi
  critici in rischio.
- Ogni criticita' apre una `SecurityFinding`; se ne esiste gia' una aperta con
  lo stesso titolo sul dispositivo viene aggiornata, non duplicata ad ogni giro.

I controlli booleani storici (`os_updated`, `antivirus_active`, …) restano e
vengono **derivati** dai campi del censimento, cosi' badge e filtri gia'
esistenti continuano a funzionare. Le soglie stanno in
`config/inventario_endpoint.php`. MFA e policy USB non sono nel CSV: restano
"non valutati".

## Storico

`App\Services\Security\EndpointHistory` legge la serie delle rilevazioni:

- `series($device, $key)` — stato ad ogni rilevazione;
- `transitions($device, $key)` — solo i cambi di stato ("LAPS da a rischio a a
  posto il 15/09");
- `riskStreak($device, $key)` — rilevazioni consecutive in rischio ("LAPS non
  configurato da 3 rilevazioni");
- `riskSince()` / `daysInRisk()` — da quando e da quanti giorni;
- `summary($device)` — tutti e sei i campi insieme.

In interfaccia: pulsante **Andamento sicurezza** sulla scheda dispositivo.

## Lo script si scarica dalla webapp, sempre aggiornato

**Security check → Scarica script** genera al volo `Inventario-Sicurezza.ps1`
con la tabella delle date di fine supporto Windows (quella che alimenta
`OS_Supporto`) compilata con i valori correnti — non si modifica piu' a mano il
file distribuito su chiavetta USB.

La fonte di verita' e' `config/inventario_endpoint.php` → `windows_eol`:

```php
'windows_eol' => [
    'dates' => [
        '21H2' => '2023-10-10',
        '22H2' => '2024-10-08',
        '23H2' => '2025-11-11',
        '24H2' => '2026-10-13',
        '25H2' => '2027-10-12',
        '26H1' => '2028-03-14',
    ],
    'windows10_eol' => '2025-10-14',
],
```

Le date vengono da qui, e da nessun'altra parte — controllare **questa pagina**
prima di ogni aggiornamento:

> **[learn.microsoft.com/en-us/lifecycle/products/windows-11-home-and-pro](https://learn.microsoft.com/en-us/lifecycle/products/windows-11-home-and-pro)**
> tabella **"Releases"** (non "Support Dates", che copre l'intera linea
> Windows 11 senza distinguere le versioni). Vale per le edizioni Home/Pro,
> quelle installate sulle macchine censite: Enterprise/Education hanno date
> diverse su un'altra pagina.

Quando esce una nuova versione (es. 26H2) si aggiunge una riga con la data
verificata li' — mai una data inventata: se non e' ancora nota, la versione
resta assente dalla tabella e lo script produce `DA VERIFICARE` per quella
macchina, che la regola `os_support` tratta correttamente come rischio (vedi
sopra) finche' non viene aggiornata la config.

`App\Services\Security\EndpointScriptBuilder` compila il template in
`resources/scripts/Inventario-Sicurezza.ps1.stub` sostituendo i placeholder
`{{TRACKFLOW:EOL_TABLE}}`, `{{TRACKFLOW:WIN10_EOL}}` e
`{{TRACKFLOW:GENERATED_AT}}`. Il download e' riservato allo staff interno
(non visibile agli account cliente).

## Come si importa

Dalla webapp: **Security check → Importa censimento**, si sceglie il cliente e
si carica il CSV.

Da riga di comando, per automatizzare il giro periodico:

```
php artisan inventario:import percorso/Inventario-Sicurezza.csv --client=1
```

`--client` accetta l'ID o il nome esatto del cliente; `--user` l'ID dell'utente
a cui attribuire le rilevazioni (di default il primo admin).
