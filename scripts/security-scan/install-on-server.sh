#!/usr/bin/env bash
# ==============================================================================
# TrackFlow — install the nightly security scan ON THE SERVER.
#
# Run this ONCE on the production server (resilient-field), as "forge":
#
#     cd /home/forge/trackflow-3tbs3e1q.on-forge.com/current/scripts/security-scan
#     bash install-on-server.sh                  # default: every day at 03:30
#     bash install-on-server.sh "0 */6 * * *"    # custom: every 6 hours
#
# Deploys here are ATOMIC: the release directory this script lives in is thrown
# away at the next deploy. So the config and the log are written OUTSIDE it, in
# ~/.trackflow-security-scan/, and the cron entry invokes the scan through the
# "current" symlink — it keeps working across deploys without reinstalling.
#
# What it does (no sudo required):
#   1. Makes the scripts executable.
#   2. Creates ~/.trackflow-security-scan/scan.conf (chmod 600) with:
#        - SCAN_MODE=local  (run checks here, no SSH)
#        - APP_DIR / APP_ENV_FILE for this site (SMTP read from the app's .env)
#        - the current authorized_keys SHA-256 as the trusted baseline
#   3. Installs/updates a single marked line in the forge user's crontab.
#
# It does NOT modify anything else on the server.
# ==============================================================================
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCHEDULE="${1:-30 3 * * *}"                 # cron expression; default 03:30 daily

# Site root = two levels above scripts/security-scan, with the release path
# collapsed back to the "current" symlink so cron never pins a dead release.
APP_DIR_DEFAULT="$(cd "$DIR/../.." && pwd)"
SITE_ROOT="${SITE_ROOT:-/home/forge/trackflow-3tbs3e1q.on-forge.com}"
if [ -L "$SITE_ROOT/current" ]; then
  APP_DIR="${APP_DIR:-$SITE_ROOT/current}"
else
  APP_DIR="${APP_DIR:-$APP_DIR_DEFAULT}"
fi
# Forge keeps a single shared .env at the site root, symlinked into each release.
APP_ENV_FILE="${APP_ENV_FILE:-$([ -e "$SITE_ROOT/.env" ] && echo "$SITE_ROOT/.env" || echo "$APP_DIR/.env")}"
MAIL_TO="${MAIL_TO:-giorgio@g8labs.it}"
HOST_LABEL="${HOST_LABEL:-trackflow-3tbs3e1q.on-forge.com}"

STATE_DIR="${STATE_DIR:-$HOME/.trackflow-security-scan}"
CONF="$STATE_DIR/scan.conf"
LOG="$STATE_DIR/scan.log"
MARK="# trackflow-security-scan"

echo "==> 1/3  Permessi di esecuzione"
chmod +x "$DIR/prod-security-scan.sh" "$DIR/remote-checks.sh" "$DIR/send_report.py"

echo "==> 2/3  Configurazione persistente ($CONF)"
mkdir -p "$STATE_DIR"
chmod 700 "$STATE_DIR"
AK="$HOME/.ssh/authorized_keys"
ak_sha=""
if [ -r "$AK" ]; then
  ak_sha="$(sha256sum "$AK" | cut -d' ' -f1)"
  echo "    baseline authorized_keys: $ak_sha"
else
  echo "    ATTENZIONE: $AK non leggibile, baseline non impostata"
fi

if [ -f "$CONF" ]; then
  echo "    scan.conf già presente: non lo sovrascrivo (controlla i valori a mano)"
else
  umask 077
  cat > "$CONF" <<CONFEOF
# Generato da install-on-server.sh — modalità server (esecuzione locale).
# Sta FUORI dalla release perché i deploy sono atomici: non va perso a ogni deploy.
SCAN_MODE="local"
HOST_LABEL="$HOST_LABEL"
MAIL_TO="$MAIL_TO"

# Deploy atomici: "current" è il symlink alla release viva.
APP_DIR="$APP_DIR"

# Le credenziali SMTP vengono lette dal .env dell'app:
APP_ENV_FILE="$APP_ENV_FILE"

# Baseline di ~/.ssh/authorized_keys: lo scan avvisa se cambia.
AUTHORIZED_KEYS_SHA256="$ak_sha"

# Advisory composer già triaggiati da non ri-segnalare ogni notte (ID, CVE o pacchetti).
DEP_AUDIT_IGNORE=""

# Rilievi noti e accettati: restano nel report ma non fanno scattare l'allarme.
# (es. ACK_FINDINGS="redis_noauth redis_exposed mysql_exposed")
ACK_FINDINGS=""

# PR aperte nell'email notturna: serve un token GitHub a SOLA LETTURA (repo privato).
GITHUB_REPO="FrancescoAnnunziata/TrackFlow"
GITHUB_TOKEN=""
CONFEOF
  chmod 600 "$CONF"
  echo "    creato $CONF (chmod 600)"
fi

echo "==> 3/3  Schedulazione (crontab utente forge)"
# Il comando passa da "current": sopravvive ai deploy senza reinstallare il cron.
RUNNER="$APP_DIR/scripts/security-scan/prod-security-scan.sh"
CMD="SCAN_CONF=$CONF /bin/bash $RUNNER >> $LOG 2>&1"
LINE="$SCHEDULE $CMD $MARK"
tmp="$(mktemp)"
crontab -l 2>/dev/null | grep -v -F "$MARK" > "$tmp" || true
echo "$LINE" >> "$tmp"
crontab "$tmp"
rm -f "$tmp"
echo "    voce cron installata:"
crontab -l | grep -F "$MARK" | sed 's/^/      /'

echo
echo "FATTO. Test immediato (esegue lo scan adesso e invia l'email):"
echo "    SCAN_CONF=$CONF bash $DIR/prod-security-scan.sh ; echo exit=\$?"
echo
echo "Log delle esecuzioni schedulate: $LOG"
echo "Per disinstallare la schedulazione:"
echo "    crontab -l | grep -v -F '$MARK' | crontab -"
