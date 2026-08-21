#!/usr/bin/env bash
# ==============================================================================
# TrackFlow — read-only IOC scan for the production host.
#
# This script performs ONLY read-only checks (ps, ss, grep, find, sha256sum,
# redis-cli ping, mysql SELECT). It never modifies the server.
#
# It is meant to be streamed to the server over SSH:
#     ssh forge@HOST 'bash -s' "<authorized_keys_baseline_sha256>" < remote-checks.sh
#
# Output: one tab-separated line per check:
#     <LEVEL>\t<check_id>\t<message>
# LEVEL in: OK | WARN | HIGH | CRIT
# The local orchestrator (prod-security-scan.sh) parses these and decides the
# overall severity + email.
#
# IOCs below come from the 2026 cryptominer incident on the Fedespedi CRM (same
# operator infrastructure, same Forge/AWS layout): reusing them here means the
# scan already knows what that intrusion looked like. Add new ones as they emerge.
# ==============================================================================
set +e
export LC_ALL=C

emit() { printf '%s\t%s\t%s\n' "$1" "$2" "$3"; }

# --- known indicators of compromise (public, safe to commit) ------------------
BASELINE_AK_SHA="${1:-}"
# Path of the deployed app on the Forge server. Deploys are ATOMIC: the code
# lives in <site>/releases/<id> and <site>/current is the symlink to the live
# one, so the checks must point at "current" (and never at a release id, which
# changes at every deploy).
APP_DIR="${APP_DIR:-/home/forge/trackflow-3tbs3e1q.on-forge.com/current}"
DROP_DIR="/var/tmp/.systemd-private-1001/.lib"
WALLET="48H5PKE5YX2ccnFkzHipMrZ6U1S8ix6Xeh72rx6aAQJvasL4MkBDW2EhobbVST91ch4PftLH4QgzVaHHrfBYQVWiAUProK3"
KNOWN_HASHES_RE="d1487fa8c36489e6e46c950484855b52d4bd3e5a6e86b9caffc7e9a3168a60f4|308d2e3b48a7f58df85a86b80d54c8c22f16ac3499dbd453d2fa5831c4730944|ddce06e8044712387426a04b8c2d443333ca0c505b93bb17ef069156bb09118b"
# common Monero/mining pool ports
MINING_PORTS_RE=":(3333|4444|5555|7777|8888|9000|10032|14433|14444|20032|45700)\b"
POOL_IP_RE="205\.172\.58\.170|moneroocean"
# tmp roots we care about
TMP_ROOTS="/tmp /var/tmp /dev/shm"
# exclude our own forensic evidence dirs (they legitimately contain the IOCs)
EVIDENCE_RE="/incident-2026|/quarantine"

# ------------------------------------------------------------------------------
# 1) Active miner process
# ------------------------------------------------------------------------------
mp=$(pgrep -af 'xmrig|systemd-private-1001|moneroocean' 2>/dev/null | grep -vE 'pgrep|remote-checks|bash -s')
if [ -n "$mp" ]; then
  emit CRIT miner_process "Processo miner ATTIVO: $(echo "$mp" | head -3 | tr '\n' ';')"
else
  emit OK miner_process "Nessun processo miner in esecuzione"
fi

# ------------------------------------------------------------------------------
# 2) Any process running from a tmp / shared-memory path
# ------------------------------------------------------------------------------
susp=""
for p in $(ls /proc 2>/dev/null | grep -E '^[0-9]+$'); do
  t=$(readlink "/proc/$p/exe" 2>/dev/null)
  case "$t" in
    /tmp/*|/var/tmp/*|/dev/shm/*) susp="$susp ${p}:${t}" ;;
  esac
done
if [ -n "$susp" ]; then
  emit HIGH proc_from_tmp "Processi eseguiti da percorsi tmp:$susp"
else
  emit OK proc_from_tmp "Nessun processo eseguito da /tmp, /var/tmp o /dev/shm"
fi

# ------------------------------------------------------------------------------
# 3) Malicious crontab entries (user running this scan, i.e. forge)
# ------------------------------------------------------------------------------
ct=$(crontab -l 2>/dev/null | grep -vE '^\s*#' \
     | grep -E 'var/tmp|\.rc-local|\.cache-sync|xmrig|moneroocean|/dev/shm|base64|curl |wget |@reboot.*tmp')
if [ -n "$ct" ]; then
  emit CRIT crontab "Crontab utente con righe sospette: $(echo "$ct" | tr '\n' ';')"
else
  emit OK crontab "Crontab utente pulito"
fi

# ------------------------------------------------------------------------------
# 4) The known hidden drop directory + any forge-owned hidden dir in tmp
# ------------------------------------------------------------------------------
if [ -e "$DROP_DIR" ]; then
  emit CRIT drop_dir "Cartella di drop nota PRESENTE: $DROP_DIR"
else
  emit OK drop_dir "Cartella di drop nota assente"
fi
me=$(id -un)
hd=$(find $TMP_ROOTS -maxdepth 2 -type d -name '.*' -user "$me" 2>/dev/null | grep -vE "$EVIDENCE_RE")
if [ -n "$hd" ]; then
  emit HIGH hidden_dir "Directory nascoste di proprietà di $me in tmp: $(echo "$hd" | tr '\n' ';')"
else
  emit OK hidden_dir "Nessuna directory nascosta sospetta in tmp"
fi

# ------------------------------------------------------------------------------
# 5) Files matching known-bad SHA-256 (limited, excluding evidence dirs)
# ------------------------------------------------------------------------------
hh=$(find $TMP_ROOTS "$HOME" -maxdepth 4 -type f -size +400k 2>/dev/null \
       | grep -vE "$EVIDENCE_RE" \
       | while read -r f; do
           s=$(sha256sum "$f" 2>/dev/null | cut -d' ' -f1)
           [ -n "$s" ] && echo "${s}|${f}"
         done | grep -iE "$KNOWN_HASHES_RE")
if [ -n "$hh" ]; then
  emit CRIT known_hash "File con hash malevolo noto: $(echo "$hh" | tr '\n' ';')"
else
  emit OK known_hash "Nessun file con hash malevolo noto"
fi

# ------------------------------------------------------------------------------
# 6) Outbound connections to a mining pool
# ------------------------------------------------------------------------------
net=$(ss -tn 2>/dev/null | grep -E 'ESTAB' | grep -E "$MINING_PORTS_RE|$POOL_IP_RE")
if [ -n "$net" ]; then
  emit CRIT mining_net "Connessioni verso pool di mining: $(echo "$net" | head -3 | tr '\n' ';')"
else
  emit OK mining_net "Nessuna connessione verso pool di mining"
fi

# ------------------------------------------------------------------------------
# 7) Recently-created executables in tmp roots (last 2 days)
# ------------------------------------------------------------------------------
ne=$(find $TMP_ROOTS -maxdepth 3 -type f -perm -u+x -mtime -2 2>/dev/null \
       | grep -vE "$EVIDENCE_RE|/systemd-private-[0-9a-f]" | head -10)
if [ -n "$ne" ]; then
  emit HIGH new_exec "Nuovi eseguibili in tmp (ultimi 2 giorni): $(echo "$ne" | tr '\n' ';')"
else
  emit OK new_exec "Nessun nuovo eseguibile sospetto in tmp"
fi

# ------------------------------------------------------------------------------
# 8) Service exposure: MySQL / Redis bound to non-loopback
# ------------------------------------------------------------------------------
mysql_listen=$(ss -tln 2>/dev/null | awk '{print $4}' | grep -E ':3306$')
mysql_listen_1l=$(echo "$mysql_listen" | tr '\n' ' ' | sed 's/[[:space:]]*$//')
MYSQL_PUBLIC=0
if echo "$mysql_listen" | grep -qE '^(0\.0\.0\.0|\*|\[::\]|::):3306$'; then
  MYSQL_PUBLIC=1
  emit WARN mysql_exposed "MySQL in ascolto su tutte le interfacce ($mysql_listen_1l) — dovrebbe essere 127.0.0.1"
elif [ -n "$mysql_listen" ]; then
  emit OK mysql_exposed "MySQL in ascolto solo in locale ($mysql_listen_1l)"
fi
redis_listen=$(ss -tln 2>/dev/null | awk '{print $4}' | grep -E ':6379$')
redis_listen_1l=$(echo "$redis_listen" | tr '\n' ' ' | sed 's/[[:space:]]*$//')
if echo "$redis_listen" | grep -qE '^(0\.0\.0\.0|\*):6379$'; then
  emit WARN redis_exposed "Redis in ascolto su tutte le interfacce ($redis_listen_1l) — dovrebbe essere 127.0.0.1"
elif [ -n "$redis_listen" ]; then
  emit OK redis_exposed "Redis in ascolto solo in locale ($redis_listen_1l)"
fi

# ------------------------------------------------------------------------------
# 9) Redis authentication
# ------------------------------------------------------------------------------
if command -v redis-cli >/dev/null 2>&1; then
  r=$(redis-cli -h 127.0.0.1 ping 2>/dev/null)
  if [ "$r" = "PONG" ]; then
    emit HIGH redis_noauth "Redis risponde a PING SENZA autenticazione (requirepass mancante)"
  elif echo "$r" | grep -qi 'NOAUTH'; then
    emit OK redis_auth "Redis richiede autenticazione"
  else
    emit OK redis_auth "Redis ping: ${r:-nessuna risposta}"
  fi
fi

# ------------------------------------------------------------------------------
# 10) Deserialization gadget signatures in current Laravel log
# ------------------------------------------------------------------------------
LOG="$APP_DIR/storage/logs/laravel.log"
if [ -r "$LOG" ]; then
  sig=$(grep -hE 'PendingBroadcast\.php|Call to undefined function (system|exec|proc_open|shell_exec|passthru)|Validator\.php:[0-9]+.*make\(\) on null|O:[0-9]+:"[A-Za-z]' "$LOG" 2>/dev/null)
  n=$(echo "$sig" | grep -c .)
  if [ "$n" -gt 0 ]; then
    emit HIGH laravel_gadget "Firme di deserializzazione (PHPGGC) nel log corrente: $n occorrenze"
  else
    emit OK laravel_gadget "Nessuna firma gadget nel log Laravel corrente"
  fi
else
  emit WARN laravel_gadget "Log Laravel non leggibile ($LOG)"
fi

# ------------------------------------------------------------------------------
# 11) Malicious payloads in the database queue (jobs / failed_jobs)
# ------------------------------------------------------------------------------
if [ -r "$APP_DIR/.env" ]; then
  DB=$(grep -E '^DB_DATABASE=' "$APP_DIR/.env" | cut -d= -f2- | tr -d '"'\' | tr -d "'")
  DU=$(grep -E '^DB_USERNAME=' "$APP_DIR/.env" | cut -d= -f2- | tr -d '"'\' | tr -d "'")
  DP=$(grep -E '^DB_PASSWORD=' "$APP_DIR/.env" | cut -d= -f2- | tr -d '"'\' | tr -d "'")
  SIG_RE='proc_open|PendingBroadcast|shell_exec|passthru|pcntl_exec|base64_decode'
  if command -v mysql >/dev/null 2>&1 && [ -n "$DB" ]; then
    jb=$(MYSQL_PWD="$DP" mysql -u"$DU" "$DB" -N -e \
         "SELECT COUNT(*) FROM jobs WHERE payload REGEXP '${SIG_RE}';" 2>/dev/null)
    fj=$(MYSQL_PWD="$DP" mysql -u"$DU" "$DB" -N -e \
         "SELECT COUNT(*) FROM failed_jobs WHERE failed_at >= (NOW() - INTERVAL 1 DAY) AND payload REGEXP '${SIG_RE}';" 2>/dev/null)
    jb=${jb:-?}; fj=${fj:-?}
    if [ "$jb" != "0" ] && [ "$jb" != "?" ]; then
      emit CRIT queue_jobs "Job in coda con payload malevolo: $jb (tabella jobs)"
    elif [ "$fj" != "0" ] && [ "$fj" != "?" ]; then
      emit HIGH queue_jobs "Job falliti malevoli nelle ultime 24h: $fj (tabella failed_jobs)"
    elif [ "$jb" = "?" ]; then
      emit WARN queue_jobs "Impossibile interrogare il DB (credenziali/permessi)"
    else
      emit OK queue_jobs "Nessun payload malevolo in jobs/failed_jobs (24h)"
    fi
  else
    emit WARN queue_jobs "Client mysql o DB_DATABASE non disponibili"
  fi
else
  emit WARN queue_jobs ".env non leggibile, salto il controllo della coda"
fi

# ------------------------------------------------------------------------------
# 12) authorized_keys integrity (vs baseline passed as $1)
# ------------------------------------------------------------------------------
AK="$HOME/.ssh/authorized_keys"
if [ -r "$AK" ]; then
  ak_sha=$(sha256sum "$AK" 2>/dev/null | cut -d' ' -f1)
  ak_n=$(grep -cE '^(ssh-|ecdsa-)' "$AK" 2>/dev/null)
  if [ -z "$BASELINE_AK_SHA" ]; then
    emit WARN authorized_keys "Baseline non impostata. Chiavi: $ak_n. SHA256 attuale: $ak_sha (impostalo in scan.conf come AUTHORIZED_KEYS_SHA256)"
  elif [ "$ak_sha" = "$BASELINE_AK_SHA" ]; then
    emit OK authorized_keys "authorized_keys invariato ($ak_n chiavi)"
  else
    emit HIGH authorized_keys "authorized_keys MODIFICATO! atteso $BASELINE_AK_SHA, trovato $ak_sha ($ak_n chiavi)"
  fi
else
  emit WARN authorized_keys "authorized_keys non leggibile"
fi

# ------------------------------------------------------------------------------
# 13) Wallet / pool string present in files under the tmp roots
# (a real miner drops its config.json here; we deliberately do NOT scan the app
#  tree, otherwise this scanner's own files — which carry the IOCs as detection
#  patterns — would match themselves)
# ------------------------------------------------------------------------------
wf=$(grep -rIl -e "$WALLET" -e 'moneroocean' \
       $TMP_ROOTS 2>/dev/null | grep -vE "$EVIDENCE_RE" | head -5)
if [ -n "$wf" ]; then
  emit CRIT wallet_string "Stringa wallet/pool trovata nei file: $(echo "$wf" | tr '\n' ';')"
else
  emit OK wallet_string "Nessun riferimento a wallet/pool nei file controllati"
fi

# ------------------------------------------------------------------------------
# 14) Known-vulnerable PHP dependencies (composer audit on the locked prod deps)
# Hygiene check: maps to WARN (never CRIT/HIGH) — a vulnerable-but-unexploited
# dependency is tech debt to patch, not an active compromise. Accepted/known
# advisories can be silenced via DEP_AUDIT_IGNORE (advisory IDs, CVEs or package
# names, space/comma separated, set in scan.conf) so only NEW ones flip the email.
# ------------------------------------------------------------------------------
PHP_BIN="$(command -v php 2>/dev/null || echo /usr/bin/php)"
COMPOSER_BIN="$(command -v composer 2>/dev/null || true)"
[ -z "$COMPOSER_BIN" ] && [ -x /usr/local/bin/composer ] && COMPOSER_BIN=/usr/local/bin/composer
if [ -n "$COMPOSER_BIN" ] && [ -r "$APP_DIR/composer.lock" ]; then
  aud=$(cd "$APP_DIR" && timeout 90 "$PHP_BIN" "$COMPOSER_BIN" audit --locked --no-dev --format=json 2>/dev/null)
  if [ -z "$aud" ]; then
    emit WARN composer_audit "composer audit non eseguibile (composer/php assenti o errore)"
  elif command -v python3 >/dev/null 2>&1; then
    summary=$(printf '%s' "$aud" | DEP_AUDIT_IGNORE="${DEP_AUDIT_IGNORE:-}" python3 -c '
import json,os,sys
from collections import Counter
try:
    adv=json.loads(sys.stdin.read()).get("advisories",{})
except Exception:
    print("ERR");sys.exit(0)
# With zero advisories composer serializes "advisories" as [] (empty PHP array)
# rather than as an object: without this normalisation .items() below raises
# AttributeError and the check reports WARN exactly when everything is fine.
# NB: no apostrophes in this block, it lives inside python3 -c *single quotes*.
if not isinstance(adv,dict):
    adv={}
ignset=set(x.strip().lower() for x in os.environ.get("DEP_AUDIT_IGNORE","").replace(","," ").split() if x.strip())
sev=Counter();pkgs=set();ignored=0
for pkg,items in adv.items():
    for a in items:
        ids={pkg.lower(),str(a.get("advisoryId","")).lower(),str(a.get("cve","")).lower()}
        if ids & ignset:
            ignored+=1;continue
        sev[(a.get("severity") or "unknown").lower()]+=1;pkgs.add(pkg)
total=sum(sev.values())
parts=", ".join(f"{sev[s]} {s}" for s in ["critical","high","medium","low","unknown"] if sev[s])
print("\t".join([str(total),str(len(pkgs)),str(ignored),parts]))
')
    tot=$(printf '%s' "$summary" | cut -f1)
    npk=$(printf '%s' "$summary" | cut -f2)
    ign=$(printf '%s' "$summary" | cut -f3)
    parts=$(printf '%s' "$summary" | cut -f4)
    if [ "$summary" = "ERR" ] || [ -z "$tot" ]; then
      emit WARN composer_audit "composer audit: output non interpretabile"
    elif [ "$tot" = "0" ]; then
      emit OK composer_audit "Nessuna dipendenza PHP vulnerabile (${ign:-0} note ignorate)"
    else
      emit WARN composer_audit "Dipendenze PHP vulnerabili: $tot advisory ($parts) su $npk pacchetti${ign:+, $ign ignorate}. Aggiorna o aggiungi a DEP_AUDIT_IGNORE."
    fi
  else
    n=$(printf '%s' "$aud" | grep -o '"advisoryId"' | wc -l | tr -d ' ')
    if [ "$n" = "0" ]; then
      emit OK composer_audit "Nessuna dipendenza PHP vulnerabile"
    else
      emit WARN composer_audit "Dipendenze PHP vulnerabili: $n advisory (composer audit --locked --no-dev)"
    fi
  fi
fi

# ------------------------------------------------------------------------------
# 15) Open GitHub pull requests (so Dependabot's fix PRs — and any forgotten PR —
# surface in the nightly email instead of sitting unseen on GitHub). Requires a
# read-only token in scan.conf (GITHUB_TOKEN) because the repo is private; the
# repo is GITHUB_REPO (owner/name). Skipped silently if not configured.
# ------------------------------------------------------------------------------
GITHUB_REPO="${GITHUB_REPO:-}"
GITHUB_TOKEN="${GITHUB_TOKEN:-}"
if [ -n "$GITHUB_REPO" ] && [ -n "$GITHUB_TOKEN" ] && command -v curl >/dev/null 2>&1; then
  pr=$(curl -s --max-time 20 \
         -H "Accept: application/vnd.github+json" \
         -H "Authorization: Bearer $GITHUB_TOKEN" \
         -H "X-GitHub-Api-Version: 2022-11-28" \
         "https://api.github.com/repos/$GITHUB_REPO/pulls?state=open&per_page=100" 2>/dev/null)
  if [ -z "$pr" ]; then
    emit WARN open_prs "Impossibile interrogare le PR GitHub (rete/timeout)"
  elif command -v python3 >/dev/null 2>&1; then
    out=$(printf '%s' "$pr" | python3 -c '
import json,sys
try:
    prs=json.loads(sys.stdin.read())
except Exception:
    print("ERR");sys.exit(0)
if not isinstance(prs,list):
    print("ERR");sys.exit(0)
def login(p): return (p.get("user") or {}).get("login","")
dep=[p for p in prs if login(p)=="dependabot[bot]"]
parts=[]
for p in prs[:6]:
    who="dependabot" if login(p)=="dependabot[bot]" else (login(p) or "?")
    parts.append("#"+str(p.get("number"))+" "+str(p.get("title",""))[:48]+" ["+who+"]")
print("\t".join([str(len(prs)),str(len(dep)),"; ".join(parts)]))
')
    tot=$(printf '%s' "$out" | cut -f1)
    dep=$(printf '%s' "$out" | cut -f2)
    lst=$(printf '%s' "$out" | cut -f3)
    if [ "$out" = "ERR" ] || [ -z "$tot" ]; then
      emit WARN open_prs "Risposta API GitHub non interpretabile (token/permessi?)"
    elif [ "$tot" = "0" ]; then
      emit OK open_prs "Nessuna PR aperta su GitHub"
    else
      emit WARN open_prs "PR aperte su GitHub: $tot (di cui $dep da Dependabot) — $lst"
    fi
  fi
fi

# ------------------------------------------------------------------------------
# 16) Laravel production hygiene (.env of the deployed app)
# Misconfiguration, not intrusion -> always WARN: APP_DEBUG on a public site
# leaks stack traces (and the environment with them), and a world-readable .env
# hands every credential to any other user on the box.
# ------------------------------------------------------------------------------
ENVF="$APP_DIR/.env"
if [ -r "$ENVF" ]; then
  envv() { grep -E "^$1=" "$ENVF" 2>/dev/null | head -1 | cut -d= -f2- \
             | sed -e 's/[[:space:]]*$//' -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }
  app_env="$(envv APP_ENV)"; app_debug="$(envv APP_DEBUG)"
  # -L: .env is a symlink to the shared <site>/.env, we want the target's mode
  perm="$(stat -Lc '%a' "$ENVF" 2>/dev/null || stat -Lf '%OLp' "$ENVF" 2>/dev/null)"
  issues=""
  case "$app_debug" in
    true|TRUE|1|on) issues="$issues APP_DEBUG=$app_debug (espone stack trace e variabili);" ;;
  esac
  case "$app_env" in
    production|prod) : ;;
    "") issues="$issues APP_ENV non impostato;" ;;
    *)  issues="$issues APP_ENV=$app_env (atteso production);" ;;
  esac
  # Only world-WRITABLE is a finding: 664 is what Forge writes and the only other
  # accounts on the box are system users, so flagging it nightly would be noise
  # that hides the real alerts. 666/777 instead means anyone can rewrite the
  # credentials — that is worth an email.
  case "$perm" in
    ''|*[!0-7]*) : ;;
    *[2367])     issues="$issues .env scrivibile da chiunque (chmod $perm, atteso 664 o meno);" ;;
  esac
  if [ -n "$issues" ]; then
    emit WARN laravel_env "Configurazione di produzione da sistemare:$issues"
  else
    emit OK laravel_env "Configurazione di produzione corretta (APP_ENV=$app_env, APP_DEBUG=${app_debug:-false}, .env $perm)"
  fi
else
  emit WARN laravel_env ".env non leggibile ($ENVF), salto i controlli di configurazione"
fi

# ------------------------------------------------------------------------------
# 17) Files that must never be reachable from the web root
# A .env or a database dump under public/ is served by nginx to anyone who
# guesses the URL: that is an exploitable exposure, hence HIGH.
# ------------------------------------------------------------------------------
PUB="$APP_DIR/public"
if [ -d "$PUB" ]; then
  leak=$(find "$PUB" -maxdepth 2 \( -name '.env*' -o -name '*.sql' -o -name '*.sql.gz' \
           -o -name '*.dump' -o -name 'composer.lock' \) 2>/dev/null | head -5)
  vcs=$(find "$PUB" -maxdepth 2 -name '.git' 2>/dev/null | head -2)
  if [ -n "$leak" ]; then
    emit HIGH docroot_leak "File sensibili raggiungibili dal web: $(echo "$leak" | tr '\n' ';')"
  elif [ -n "$vcs" ]; then
    emit WARN docroot_leak "Repository .git dentro la docroot: $(echo "$vcs" | tr '\n' ';')"
  else
    emit OK docroot_leak "Nessun file sensibile nella docroot ($PUB)"
  fi
else
  emit WARN docroot_leak "Docroot non trovata ($PUB): APP_DIR punta al path giusto?"
fi

# ------------------------------------------------------------------------------
# 18) MySQL accounts that accept connections from any host ('%')
# Only reported when MySQL is actually listening on a public interface: with the
# socket bound to 127.0.0.1 a wildcard account has no way in, and warning about
# it anyway would be noise. The two together are the exposure.
# ------------------------------------------------------------------------------
if [ "${MYSQL_PUBLIC:-0}" = "1" ] && [ -r "$APP_DIR/.env" ] && command -v mysql >/dev/null 2>&1; then
  DB=$(grep -E '^DB_DATABASE=' "$APP_DIR/.env" | cut -d= -f2- | tr -d '"'\' | tr -d "'")
  DU=$(grep -E '^DB_USERNAME=' "$APP_DIR/.env" | cut -d= -f2- | tr -d '"'\' | tr -d "'")
  DP=$(grep -E '^DB_PASSWORD=' "$APP_DIR/.env" | cut -d= -f2- | tr -d '"'\' | tr -d "'")
  wu=$(MYSQL_PWD="$DP" mysql -u"$DU" -N -e \
       "SELECT CONCAT(user,'@',host) FROM mysql.user WHERE host IN ('%','0.0.0.0');" 2>/dev/null \
       | tr '\n' ' ' | sed 's/[[:space:]]*$//')
  if [ -n "$wu" ]; then
    emit WARN mysql_wildcard_users "MySQL e' pubblico E ha utenti raggiungibili da qualsiasi host: $wu — bindalo su 127.0.0.1 (scripts/server/harden-services.sh)"
  else
    emit OK mysql_wildcard_users "Nessun utente MySQL con host jolly"
  fi
fi

# ------------------------------------------------------------------------------
# Scan footer (always OK; lets the orchestrator confirm the scan completed)
# ------------------------------------------------------------------------------
emit OK scan_complete "Scan completato su $(hostname) come $(id -un) — uptime:$(uptime | sed 's/^ *//')"
