# TrackFlow — Monitor di sicurezza notturno

Scanner **in sola lettura** che ogni notte controlla il server di produzione,
cerca indicatori di compromissione (cryptominer, persistenze, esposizioni di
servizi, payload malevoli nella coda Laravel, modifiche a `authorized_keys`,
ecc.) e invia un **report via email** a `giorgio@g8labs.it`.

L'email arriva **anche quando è tutto a posto**: è il punto del monitor. Il
silenzio non distingue "nessun problema" da "lo scan non è partito", quindi ogni
notte c'è una riga in casella che dice quale dei due è.

| Esito scan | Oggetto email | Priorità |
|---|---|---|
| Trovati segni attivi / sospetti forti (CRIT/HIGH) | 🚨 **URGENTE — possibile compromissione** | Alta (flag urgente) |
| Solo avvisi di configurazione (WARN) | ⚠️ scan: N avvisi (non critico) | Normale |
| Tutto a posto | ✅ scan OK (nessun problema) | Normale |
| Scan non eseguibile (es. SSH irraggiungibile) | ⚠️ scan **NON eseguito** | Normale |

Lo scanner **non modifica mai** il server: esegue solo `ps`, `ss`, `grep`,
`find`, `sha256sum`, `stat`, `redis-cli ping`, `composer audit` e `SELECT` di
sola lettura.

## Il server di TrackFlow

| Cosa | Valore |
|---|---|
| Host | `resilient-field` (Tailscale MagicDNS — si usa il nome, non l'IP) |
| Utente | `forge` |
| Sito | `/home/forge/trackflow-3tbs3e1q.on-forge.com` |
| Codice vivo | `<sito>/current` → `<sito>/releases/<id>` (**deploy atomici**) |
| `.env` | `<sito>/.env`, condiviso e symlinkato dentro ogni release |

I deploy atomici cambiano due cose rispetto a un layout classico:

- `APP_DIR` deve puntare a **`current`**, mai a una release (sparisce al deploy
  successivo);
- `scan.conf` e `scan.log` **non possono stare nella release**, o li perdi ogni
  volta che deployi. L'installer li scrive in `~/.trackflow-security-scan/` e il
  cron passa il path con `SCAN_CONF=`.

## File

| File | Cosa fa |
|---|---|
| `prod-security-scan.sh` | Orchestratore: lancia lo scan (in locale o via SSH), valuta i risultati, invia l'email. |
| `remote-checks.sh` | I controlli veri e propri (read-only). Eseguibili sul server o inviati via SSH. |
| `send_report.py` | Invio email via SMTP (priorità alta se urgente). |
| `install-on-server.sh` | Installer da lanciare **sul server**: schedula lo scan via cron. |
| `scan.conf.example` | Template di configurazione (per la modalità SSH dal Mac). |
| `com.g8labs.trackflow-security-scan.plist.example` | Job launchd per la schedulazione su macOS (modalità SSH). |

`scan.conf` e `scan.log` sono **gitignored** (contengono password / output) e non
vanno committati.

## Due modalità di esecuzione

| Modo | Dove gira | Quando usarlo | Credenziali SMTP |
|---|---|---|---|
| **`local`** (consigliato) | sul server, via cron | controllo notturno affidabile | lette dal `.env` dell'app |
| **`ssh`** | dal Mac, via launchd | controllo indipendente dal server | da `scan.conf` |

---

## A) Setup sul SERVER (consigliato) — `SCAN_MODE=local`

Affidabile (il server è sempre acceso), niente dipendenza dal Mac o dalla rete
di casa, e riusa le credenziali SMTP del `.env` dell'app
(`mail.notifications-g8labs.it`): **non serve gestire password a mano**.

Serve che il repo sia già deployato (gli script arrivano sul server con il
deploy Forge). Poi, da utente `forge`:

```bash
ssh forge@resilient-field
cd /home/forge/trackflow-3tbs3e1q.on-forge.com/current/scripts/security-scan
bash install-on-server.sh                 # default: ogni giorno alle 03:30
# oppure un intervallo diverso, es. ogni 6 ore:
# bash install-on-server.sh "0 */6 * * *"
```

L'installer:
1. rende eseguibili gli script;
2. crea `~/.trackflow-security-scan/scan.conf` (chmod 600, fuori dalla release)
   con `SCAN_MODE=local`, `APP_DIR` su `current`, il path del `.env` per l'SMTP
   e la **baseline** SHA-256 di `~/.ssh/authorized_keys`;
3. aggiunge **una** riga al crontab di `forge` (marcata `# trackflow-security-scan`),
   che invoca lo scan attraverso `current`: resta valida a ogni deploy.

Test immediato (esegue lo scan adesso e manda l'email):

```bash
SCAN_CONF=~/.trackflow-security-scan/scan.conf \
  bash /home/forge/trackflow-3tbs3e1q.on-forge.com/current/scripts/security-scan/prod-security-scan.sh
echo "exit=$?"
```

Disinstallare la schedulazione:

```bash
crontab -l | grep -v -F '# trackflow-security-scan' | crontab -
```

> Nota: il cron sta nel crontab dell'utente `forge` (nessun `sudo`). In
> alternativa lo stesso comando si può mettere come
> **Forge → Server → Scheduled Jobs** (scrive in `/etc/crontab` come root).

---

## B) Setup dal MAC — `SCAN_MODE=ssh`

Da usare se vuoi un controllo **indipendente** dal server (lo scansiona da
fuori: se il server è compromesso, un attaccante può zittire il cron che gira
lì dentro, non quello che gira sul tuo Mac).

```bash
cd scripts/security-scan
cp scan.conf.example scan.conf && chmod 600 scan.conf
# compila scan.conf: credenziali SMTP (il resto è già impostato)
./prod-security-scan.sh        # primo run di prova
```

Al primo run il controllo `authorized_keys` ti manda il suo SHA-256: copialo in
`scan.conf` come `AUTHORIZED_KEYS_SHA256`, così lo scan ti avvisa se cambia.

Schedulazione su macOS (launchd):

```bash
cp com.g8labs.trackflow-security-scan.plist.example \
   ~/Library/LaunchAgents/com.g8labs.trackflow-security-scan.plist
launchctl load ~/Library/LaunchAgents/com.g8labs.trackflow-security-scan.plist
launchctl start com.g8labs.trackflow-security-scan      # prova immediata
```

⚠️ In modalità SSH dal Mac: il Mac deve essere **sveglio** all'orario previsto e
**Tailscale attivo**, perché la connessione passa dalla tailnet. Se non si
collega ricevi l'email "scan NON eseguito" — è voluto, così non resti
all'oscuro.

## Rimedi

I due script in [`scripts/server/`](../server/) sistemano quello che il monitor
segnala sul sistema, e vanno lanciati **come root sul server**:

| Script | Cosa fa |
|---|---|
| `harden-services.sh` | Binda MySQL e Redis su `127.0.0.1` (rimedia ai check 8, 9 e 18). Backup dei file, idempotente, `--dry-run` disponibile. |
| `update-system.sh` | `apt upgrade` con le config preservate e i servizi riavviati. Non riavvia la macchina senza `--reboot`, e non lo fa comunque se `tailscaled` non e' abilitato al boot: la 22 e' chiusa da fuori, senza tailnet dopo il riavvio si resta fuori. |

## Cosa controlla lo scan

1. Processo miner (xmrig / moneroocean) attivo
2. Processi eseguiti da `/tmp`, `/var/tmp`, `/dev/shm`
3. Crontab utente con righe sospette
4. Cartella di drop nota + directory nascoste in tmp
5. File con SHA-256 malevolo noto
6. Connessioni in uscita verso pool di mining
7. Nuovi eseguibili in tmp (ultimi 2 giorni)
8. MySQL / Redis in ascolto su interfacce pubbliche
9. Redis senza password **e** in ascolto pubblico (solo loopback: non e' un rilievo)
10. Firme di deserializzazione (gadget PHPGGC) nei log Laravel
11. Payload malevoli nella coda DB (`jobs` / `failed_jobs`)
12. Integrità di `~/.ssh/authorized_keys` (vs baseline)
13. Stringa wallet/pool presente nei file
14. Dipendenze PHP con advisory di sicurezza note (`composer audit`)
15. PR aperte su GitHub (incluse quelle di Dependabot) — promemoria nell'email
16. Igiene della configurazione di produzione (`APP_DEBUG`, `APP_ENV`, permessi `.env`)
17. File che non devono essere raggiungibili dal web (`.env`, dump SQL, `.git` in `public/`)
18. Utenti MySQL raggiungibili da qualsiasi host (solo se MySQL e' anche esposto)
19. Worker della coda vivo e coda che scorre (job fermi da oltre 30 minuti, job falliti)

Gli IOC (hash, wallet, pool) vengono dall'incidente cryptominer del 2026 sul CRM
Fedespedi: stessa infrastruttura d'attacco, stesso layout Forge/AWS, quindi lo
scan qui parte già sapendo che aspetto aveva quell'intrusione. Aggiornali in
`remote-checks.sh` se emergono nuovi indicatori.

### Perché alcuni check non diventano mai "urgenti"

L'email urgente è riservata agli **IOC attivi**. I check 14, 16 e 17 (dipendenze
vulnerabili, `APP_DEBUG`, permessi) sono debito da sistemare, non
un'infezione in corso: restano WARN, così quando arriva un 🚨 significa davvero
qualcosa. L'unica eccezione è un `.env` o un dump SQL **scaricabile dal web**
(check 17), che è HIGH: non è una compromissione, ma è la porta aperta che ne
provoca una.

### Perche' c'e' un check sulla coda in un monitor di sicurezza (19)

Perche' l'email quotidiana serve a sapere se il sistema sta bene, e il guasto
muto e' il piu' pericoloso di tutti. Il 21/08/2026 il riavvio di MySQL durante
un `apt upgrade` ha fatto fallire il queue worker abbastanza volte da mandarlo
in FATAL su supervisor, che a quel punto smette di riprovare. MySQL e' tornato
su, il sito rispondeva 200, e la coda e' rimasta ferma senza che niente lo
dicesse. `supervisorctl` richiede root, quindi il check guarda il processo
(`pgrep`) e l'eta' dei job in coda: entrambi leggibili dall'utente `forge`.

### Tenere verde la mail: `ACK_FINDINGS`

Un rilievo già valutato e accettato si elenca in `ACK_FINDINGS` (id dei check,
separati da spazi) dentro `scan.conf`: continua a comparire nel report in una
sezione **ACCETTATI**, ma non decide più la severità dell'email. Non è un
"nascondi": è quello che tiene leggibile il monitor. Un 🚨 che arriva tutte le
notti per una cosa nota smette di essere aperto, ed è esattamente così che passa
inosservato quello vero.

Situazione del server ad agosto 2026: **Redis gira senza password** e in ascolto
su `0.0.0.0`, **MySQL** su `bind-address = *`. Dall'esterno sono irraggiungibili
(il Security Group AWS chiude 3306, 6379 e pure la 22: si entra solo da
Tailscale) e TrackFlow non usa Redis (sessioni, code e cache stanno su
database, 0 chiavi). Il rimedio giusto però è bindarli su `127.0.0.1` — se
invece li si mette in `ACK_FINDINGS`, è una decisione presa, non una svista.

### Nota sul check `composer audit` (14)

Esegue `composer audit --locked --no-dev` e segnala le dipendenze vulnerabili.
Per non ricevere un avviso ogni notte sugli advisory **già noti e accettati**
(es. una CVE senza patch stabile), elencali in `DEP_AUDIT_IGNORE` dentro
`scan.conf` (ID advisory, CVE o nomi pacchetto): così solo i **nuovi** advisory
fanno scattare il WARN.

### Nota sul check `open_prs` (15)

Elenca nell'email le PR aperte sul repo (è un **WARN** finché ce ne sono, così
non te le dimentichi), evidenziando quelle di **Dependabot**. Richiede
`GITHUB_REPO` e un `GITHUB_TOKEN` a sola lettura in `scan.conf` (fine-grained
PAT limitato a questo repo, permesso "Pull requests: Read-only"). Senza token il
check è disattivato e non compare nell'email.
