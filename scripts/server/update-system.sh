#!/usr/bin/env bash
# ==============================================================================
# TrackFlow — aggiornamento dei pacchetti di sistema del server di produzione.
#
# DA LANCIARE SUL SERVER COME ROOT:
#     scp scripts/server/update-system.sh forge@resilient-field:/tmp/
#     ssh -t forge@resilient-field 'sudo bash /tmp/update-system.sh'
#
#     --dry-run   elenca cosa verrebbe aggiornato, non installa niente
#     --reboot    riavvia alla fine SE serve e SE il pre-flight passa
#
# Perche' non e' un semplice "apt upgrade -y":
#
#   1. PRE-FLIGHT TAILSCALE. La porta 22 e' chiusa dal Security Group AWS: al
#      server si arriva solo dalla tailnet. Se tailscaled non e' abilitato al
#      boot, dopo un riavvio non si rientra piu' (e la console seriale EC2 e'
#      l'unica strada). Il server ha ~591 giorni di uptime: che Tailscale risalga
#      da solo non e' mai stato verificato. Lo script controlla prima, e senza
#      quella conferma non riavvia.
#
#   2. CONFIG INVARIATE. --force-confold tiene i file di configurazione attuali:
#      nginx e php-fpm sono gestiti da Forge, un file sovrascritto dal
#      pacchetto significa sito giu'.
#
#   3. RESTART DEI SERVIZI. NEEDRESTART_MODE=a riavvia i servizi che stanno
#      ancora usando librerie vecchie: senza, si aggiorna openssl e i processi
#      continuano col codice bucato in memoria.
# ==============================================================================
set -euo pipefail

DRY_RUN=0; DO_REBOOT=0
for a in "$@"; do
  case "$a" in
    --dry-run) DRY_RUN=1 ;;
    --reboot)  DO_REBOOT=1 ;;
    *) echo "Opzione sconosciuta: $a" >&2; exit 2 ;;
  esac
done

if [ "$DRY_RUN" = "0" ] && [ "$(id -u)" != "0" ]; then
  echo "✗ Serve root: sudo bash $0" >&2
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a
APT_OPTS='-y -o Dpkg::Options::=--force-confold -o Dpkg::Options::=--force-confdef'

say() { echo; echo "==> $*"; }

say "1/5  Aggiorno gli indici dei pacchetti"
apt-get update -qq

say "2/5  Cosa verrebbe aggiornato"
n="$(apt list --upgradable 2>/dev/null | grep -c upgradable || true)"
echo "    $n pacchetti aggiornabili"
apt list --upgradable 2>/dev/null | grep -E '^(linux-image|php|nginx|mysql|redis|openssl|libssl|tailscale)' | sed 's/^/    /' || true

if [ "$DRY_RUN" = "1" ]; then
  echo
  echo "[dry-run] mi fermo qui, niente installato."
  exit 0
fi

say "3/5  Upgrade (config attuali preservate, servizi riavviati dove serve)"
# shellcheck disable=SC2086
apt-get $APT_OPTS upgrade
# shellcheck disable=SC2086
apt-get $APT_OPTS autoremove --purge
apt-get autoclean -qq

say "4/5  Stato dei servizi"
for s in nginx php8.3-fpm mysql redis-server tailscaled; do
  printf '    %-16s %s\n' "$s" "$(systemctl is-active "$s" 2>/dev/null || echo assente)"
done
echo "    php: $(php -v 2>/dev/null | head -1)"
code="$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: trackflow-3tbs3e1q.on-forge.com' http://127.0.0.1/ || echo ERR)"
echo "    app HTTP: $code"

say "5/5  Riavvio"
if [ ! -e /var/run/reboot-required ]; then
  echo "    Non serve riavviare."
  exit 0
fi

echo "    Il riavvio SERVE (kernel in esecuzione: $(uname -r))."
echo "    Kernel piu' recente installato: $(ls -1 /boot/vmlinuz-* 2>/dev/null | sed 's|.*vmlinuz-||' | sort -V | tail -1)"

# Pre-flight: senza Tailscale al boot, dopo il reboot si resta fuori.
ts_enabled="$(systemctl is-enabled tailscaled 2>/dev/null || echo no)"
ts_active="$(systemctl is-active tailscaled 2>/dev/null || echo no)"
echo "    tailscaled: enabled=$ts_enabled active=$ts_active"
ssh_public="no"
grep -qsE '^\s*Port\s+22' /etc/ssh/sshd_config && ssh_public="porta 22 in ascolto (ma filtrata dal Security Group)"
echo "    sshd: $ssh_public"

if [ "$ts_enabled" != "enabled" ]; then
  echo
  echo "    ✗ NON riavvio: tailscaled non e' abilitato al boot ($ts_enabled)."
  echo "      Senza di lui, dopo il riavvio non si rientra (la 22 e' chiusa da fuori)."
  echo "      Sistemalo prima:  systemctl enable tailscaled"
  exit 3
fi

if [ "$DO_REBOOT" = "0" ]; then
  echo
  echo "    Pre-flight ok, ma non riavvio senza --reboot."
  echo "    Quando vuoi:  sudo bash $0 --reboot     (oppure: sudo reboot)"
  echo "    Il rientro:   ssh forge@resilient-field   (attendi ~60s)"
  exit 0
fi

echo "    Pre-flight ok. Riavvio fra 10 secondi (Ctrl-C per annullare)..."
sleep 10
systemctl reboot
