#!/usr/bin/env bash
# ==============================================================================
# TrackFlow — security scan orchestrator.
#
# Runs the read-only IOC checks (remote-checks.sh), evaluates the results,
# and emails a severity-tiered report to MAIL_TO.
#
# Two execution modes:
#   SCAN_MODE=local  -> checks run on THIS machine (use this when scheduled by
#                       cron ON the production server). Mail settings are read
#                       from the app's .env (MAIL_*) unless overridden.
#   SCAN_MODE=ssh    -> checks are streamed to a remote host over SSH (use this
#                       when running from a laptop). Mail settings come from
#                       scan.conf. (default)
#
# Severity -> email:
#   urgent -> any CRIT or HIGH finding (high-priority email)
#   warn   -> only WARN findings, OR the scan could not run
#   clean  -> everything OK
#
# It NEVER modifies the monitored system: only ps/ss/grep/find/sha256sum/
# redis-cli ping / read-only SELECT.
# ==============================================================================
set -uo pipefail
export LC_ALL=C

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONF="${SCAN_CONF:-$DIR/scan.conf}"
REMOTE="${SCAN_CHECKS:-$DIR/remote-checks.sh}"
SENDER="$DIR/send_report.py"

# ---- load optional configuration file ----------------------------------------
if [ -f "$CONF" ]; then
  # shellcheck disable=SC1090
  . "$CONF"
fi

SCAN_MODE="${SCAN_MODE:-ssh}"
SSH_TARGET="${SSH_TARGET:-forge@resilient-field}"   # nome Tailscale, non IP
SSH_PORT="${SSH_PORT:-22}"
SSH_KEY="${SSH_KEY:-$HOME/.ssh/id_rsa}"
SSH_TIMEOUT="${SSH_TIMEOUT:-25}"
AUTHORIZED_KEYS_SHA256="${AUTHORIZED_KEYS_SHA256:-}"
APP_ENV_FILE="${APP_ENV_FILE:-/home/forge/trackflow-3tbs3e1q.on-forge.com/.env}"
APP_DIR="${APP_DIR:-/home/forge/trackflow-3tbs3e1q.on-forge.com/current}"; export APP_DIR
MAIL_TO="${MAIL_TO:-giorgio@g8labs.it}"
DEP_AUDIT_IGNORE="${DEP_AUDIT_IGNORE:-}"; export DEP_AUDIT_IGNORE
# Rilievi consapevolmente accettati (id dei check, separati da spazi/virgole):
# restano nel report, in una sezione a parte, ma non decidono piu' la severita'.
# Serve per non ricevere un allarme rosso ogni notte per una cosa gia' valutata,
# che altrimenti finirebbe per far ignorare anche gli allarmi veri.
ACK_FINDINGS="${ACK_FINDINGS:-}"
GITHUB_REPO="${GITHUB_REPO:-FrancescoAnnunziata/TrackFlow}"; export GITHUB_REPO
GITHUB_TOKEN="${GITHUB_TOKEN:-}"; export GITHUB_TOKEN

if [ "$SCAN_MODE" = "local" ]; then
  HOST_LABEL="${HOST_LABEL:-$(hostname)}"
else
  HOST_LABEL="${HOST_LABEL:-$SSH_TARGET}"
fi

# ---- derive SMTP settings from the app's .env when not already provided ------
# (handy in local/server mode: reuse the application's mail credentials)
envget() {
  grep -E "^$1=" "$2" 2>/dev/null | head -1 | cut -d= -f2- \
    | sed -e 's/[[:space:]]*$//' -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}
if [ -z "${SMTP_HOST:-}" ] && [ -r "$APP_ENV_FILE" ]; then
  SMTP_HOST="$(envget MAIL_HOST "$APP_ENV_FILE")"
  SMTP_PORT="${SMTP_PORT:-$(envget MAIL_PORT "$APP_ENV_FILE")}"
  SMTP_USER="${SMTP_USER:-$(envget MAIL_USERNAME "$APP_ENV_FILE")}"
  SMTP_PASS="${SMTP_PASS:-$(envget MAIL_PASSWORD "$APP_ENV_FILE")}"
  MAIL_FROM="${MAIL_FROM:-$(envget MAIL_FROM_ADDRESS "$APP_ENV_FILE")}"
  MAIL_FROM_NAME="${MAIL_FROM_NAME:-$(envget MAIL_FROM_NAME "$APP_ENV_FILE")}"
  enc="$(envget MAIL_ENCRYPTION "$APP_ENV_FILE")"
  case "$enc" in
    ssl|SSL)             SMTP_SECURITY="${SMTP_SECURITY:-ssl}" ;;
    tls|TLS|starttls)    SMTP_SECURITY="${SMTP_SECURITY:-starttls}" ;;
    *) [ "${SMTP_PORT:-}" = "465" ] && SMTP_SECURITY="${SMTP_SECURITY:-ssl}" || SMTP_SECURITY="${SMTP_SECURITY:-starttls}" ;;
  esac
fi

# export mail settings for send_report.py
export SMTP_HOST SMTP_PORT SMTP_SECURITY SMTP_USER SMTP_PASS MAIL_FROM MAIL_FROM_NAME MAIL_TO

now="$(date '+%Y-%m-%d %H:%M:%S %Z')"
errfile="$(mktemp 2>/dev/null || echo /tmp/trackflow-scan.err)"

send() { # severity subject  (body on stdin)
  if command -v python3 >/dev/null 2>&1; then
    python3 "$SENDER" --severity "$1" --subject "$2"
  else
    echo "[scan] python3 non disponibile, non posso inviare email" >&2
    cat >/dev/null
  fi
}

# ---- run the checks (local or over SSH) --------------------------------------
if [ "$SCAN_MODE" = "local" ]; then
  raw="$(bash "$REMOTE" "$AUTHORIZED_KEYS_SHA256" 2>"$errfile")"
  rc=$?
else
  raw="$(ssh -o BatchMode=yes \
             -o ConnectTimeout="$SSH_TIMEOUT" \
             -o StrictHostKeyChecking=accept-new \
             -p "$SSH_PORT" -i "$SSH_KEY" \
             "$SSH_TARGET" "APP_DIR='$APP_DIR' DEP_AUDIT_IGNORE='$DEP_AUDIT_IGNORE' GITHUB_REPO='$GITHUB_REPO' GITHUB_TOKEN='$GITHUB_TOKEN' bash -s" "$AUTHORIZED_KEYS_SHA256" < "$REMOTE" 2>"$errfile")"
  rc=$?
fi

# ---- handle a scan that could not run (visible warning, never silent) --------
if [ $rc -ne 0 ] || [ -z "$raw" ]; then
  err="$(cat "$errfile" 2>/dev/null)"
  {
    echo "ATTENZIONE: la scansione di sicurezza NON è stata eseguita."
    echo
    echo "Host:    $HOST_LABEL"
    echo "Quando:  $now"
    echo "Modo:    $SCAN_MODE"
    echo "Esito:   codice $rc"
    echo
    if [ "$SCAN_MODE" = "ssh" ]; then
      echo "Possibili cause: IP non in whitelist nel Security Group AWS (porta 22),"
      echo "server irraggiungibile, o chiave SSH errata ($SSH_KEY)."
    else
      echo "Possibili cause: script dei controlli mancante/non eseguibile,"
      echo "oppure comandi di base non disponibili sul sistema."
    fi
    echo
    echo "Dettaglio errore:"
    echo "  ${err:-<nessuno>}"
    echo
    echo "Una scansione che non parte può mascherare una compromissione: verificare."
  } | send warn "⚠️ TrackFlow — scan NON eseguito ($HOST_LABEL)"
  rm -f "$errfile"
  exit 1
fi
rm -f "$errfile"

# ---- parse results -----------------------------------------------------------
crit=(); high=(); warn=(); ok=(); ack=()
ack_list=" $(echo "$ACK_FINDINGS" | tr ',' ' ' | tr -s ' ') "
while IFS=$'\t' read -r level id msg; do
  [ -z "${level:-}" ] && continue
  # An acknowledged finding is still reported, but in its own section: it must
  # not turn the subject red, or the red stops meaning anything.
  if [ "$level" != "OK" ] && [ "$ack_list" != "  " ] && \
     case "$ack_list" in *" $id "*) true ;; *) false ;; esac; then
    ack+=("[$id] ($level) $msg")
    continue
  fi
  case "$level" in
    CRIT) crit+=("[$id] $msg") ;;
    HIGH) high+=("[$id] $msg") ;;
    WARN) warn+=("[$id] $msg") ;;
    OK)   ok+=("[$id] $msg") ;;
  esac
done <<< "$raw"

n_crit=${#crit[@]}; n_high=${#high[@]}; n_warn=${#warn[@]}; n_ok=${#ok[@]}; n_ack=${#ack[@]}

# ---- decide overall severity + subject --------------------------------------
if [ "$n_crit" -gt 0 ] || [ "$n_high" -gt 0 ]; then
  severity="urgent"
  subject="🚨 URGENTE — TrackFlow: possibile compromissione ($((n_crit+n_high)) segnalazioni)"
  banner="!!! RICHIEDE ATTENZIONE IMMEDIATA — POSSIBILE COMPROMISSIONE !!!"
elif [ "$n_warn" -gt 0 ]; then
  severity="warn"
  subject="⚠️ TrackFlow — scan: $n_warn avvisi (non critico)"
  banner="Scan completato con alcuni avvisi (configurazione/esposizione). Nessun segno attivo di compromissione."
else
  severity="clean"
  if [ "$n_ack" -gt 0 ]; then
    subject="✅ TrackFlow — scan OK ($n_ack rilievi accettati)"
  else
    subject="✅ TrackFlow — scan OK (nessun problema)"
  fi
  banner="Tutto regolare. Nessun indicatore di compromissione rilevato."
fi

# ---- build report body -------------------------------------------------------
build_report() {
  echo "$banner"
  echo
  echo "Host:    $HOST_LABEL"
  echo "Quando:  $now"
  echo "Esito:   $n_crit critici, $n_high alti, $n_warn avvisi, $n_ok controlli ok${n_ack:+, $n_ack accettati}"
  echo
  echo "================================================================"
  if [ "$n_crit" -gt 0 ]; then
    echo "### CRITICI (segni attivi di compromissione)"
    for x in "${crit[@]}"; do echo "  ✗ $x"; done
    echo
  fi
  if [ "$n_high" -gt 0 ]; then
    echo "### ALTI (sospetti forti / esposizioni sfruttabili)"
    for x in "${high[@]}"; do echo "  ✗ $x"; done
    echo
  fi
  if [ "$n_warn" -gt 0 ]; then
    echo "### AVVISI (da sistemare, non un'infezione attiva)"
    for x in "${warn[@]}"; do echo "  ! $x"; done
    echo
  fi
  if [ "$n_ack" -gt 0 ]; then
    echo "### ACCETTATI (noti e valutati, elencati in ACK_FINDINGS)"
    for x in "${ack[@]}"; do echo "  ~ $x"; done
    echo
  fi
  echo "### CONTROLLI SUPERATI"
  for x in "${ok[@]}"; do echo "  ✓ $x"; done
  echo
  echo "================================================================"
  if [ "$severity" = "urgent" ]; then
    echo "AZIONI CONSIGLIATE:"
    echo "  1. NON spegnere/riavviare prima di salvare le evidenze."
    echo "  2. Applicare il piano di hardening interim"
    echo "     (vedi scripts/security-scan/README.md)."
    echo "  3. Considerare il rebuild del server da zero."
    echo
  fi
  echo "Report generato automaticamente dal monitor di sicurezza di TrackFlow."
  echo "Scanner: scripts/security-scan/ — controlli in sola lettura — modo: $SCAN_MODE."
}

build_report | send "$severity" "$subject"

# exit code mirrors severity (useful for the scheduler log)
case "$severity" in
  urgent) exit 10 ;;
  warn)   exit 1  ;;
  *)      exit 0  ;;
esac
