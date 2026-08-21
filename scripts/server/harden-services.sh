#!/usr/bin/env bash
# ==============================================================================
# TrackFlow — chiude MySQL e Redis su localhost (server di produzione).
#
# Rimedia ai check 8 (mysql_exposed / redis_exposed) e 9 (redis_noauth) del
# monitor di sicurezza: MySQL ascolta su "bind-address = *" e Redis su 0.0.0.0
# senza password. Oggi dall'esterno non si raggiungono perche' il Security Group
# AWS chiude 3306/6379/22 (si entra solo da Tailscale), ma il firewall e' l'unica
# cosa che li protegge: basta una regola aperta per sbaglio e sono esposti.
# L'app usa 127.0.0.1 per entrambi, quindi il bind locale non le cambia niente.
#
# DA LANCIARE SUL SERVER COME ROOT:
#     scp scripts/server/harden-services.sh forge@resilient-field:/tmp/
#     ssh -t forge@resilient-field 'sudo bash /tmp/harden-services.sh'
#
#     --dry-run   mostra cosa cambierebbe senza toccare niente
#
# Fa un backup timestampato di ogni file prima di modificarlo, e' idempotente
# (rilanciarlo non fa danni) e verifica i servizi dopo il restart.
# ==============================================================================
set -euo pipefail

DRY_RUN=0
[ "${1:-}" = "--dry-run" ] && DRY_RUN=1

STAMP="$(date +%Y%m%d-%H%M%S)"
REDIS_CONF="/etc/redis/redis.conf"
MYSQL_CONF="/etc/mysql/mysql.conf.d/mysqld.cnf"
SITE="trackflow-3tbs3e1q.on-forge.com"

if [ "$DRY_RUN" = "0" ] && [ "$(id -u)" != "0" ]; then
  echo "✗ Serve root: sudo bash $0" >&2
  exit 1
fi

say()  { echo "==> $*"; }
run()  { if [ "$DRY_RUN" = "1" ]; then echo "    [dry-run] $*"; else eval "$@"; fi; }

backup() {
  local f="$1"
  [ -f "$f" ] || return 0
  run "cp -a '$f' '$f.bak-$STAMP'"
  echo "    backup: $f.bak-$STAMP"
}

# ------------------------------------------------------------------------------
say "1/4  Redis -> solo localhost"
if [ -f "$REDIS_CONF" ]; then
  cur="$(grep -E '^[[:space:]]*bind ' "$REDIS_CONF" | head -1 || true)"
  echo "    bind attuale: ${cur:-<nessuna riga bind>}"
  if echo "$cur" | grep -q '^[[:space:]]*bind 127\.0\.0\.1'; then
    echo "    gia' su localhost, non tocco niente"
  else
    backup "$REDIS_CONF"
    if [ -n "$cur" ]; then
      run "sed -i 's/^[[:space:]]*bind .*/bind 127.0.0.1 -::1/' '$REDIS_CONF'"
    else
      run "printf '\nbind 127.0.0.1 -::1\n' >> '$REDIS_CONF'"
    fi
    # protected-mode e' la seconda rete di sicurezza: senza password Redis
    # rifiuta comunque i comandi che non arrivano dal loopback.
    if grep -qE '^[[:space:]]*protected-mode ' "$REDIS_CONF"; then
      run "sed -i 's/^[[:space:]]*protected-mode .*/protected-mode yes/' '$REDIS_CONF'"
    else
      run "printf 'protected-mode yes\n' >> '$REDIS_CONF'"
    fi
    run "systemctl restart redis-server"
    echo "    redis riavviato"
  fi
else
  echo "    $REDIS_CONF non trovato, salto"
fi

# ------------------------------------------------------------------------------
say "2/4  MySQL -> solo localhost"
if [ -f "$MYSQL_CONF" ]; then
  cur="$(grep -E '^[[:space:]]*bind-address' "$MYSQL_CONF" | head -1 || true)"
  echo "    bind attuale: ${cur:-<nessuna riga bind-address>}"
  if echo "$cur" | grep -qE 'bind-address[[:space:]]*=[[:space:]]*127\.0\.0\.1'; then
    echo "    gia' su localhost, non tocco niente"
  else
    backup "$MYSQL_CONF"
    if [ -n "$cur" ]; then
      run "sed -i 's/^[[:space:]]*bind-address.*/bind-address = 127.0.0.1/' '$MYSQL_CONF'"
    else
      run "printf '\nbind-address = 127.0.0.1\n' >> '$MYSQL_CONF'"
    fi
    # Il riavvio di MySQL e' l'unico momento di disservizio: qualche secondo in
    # cui l'app risponde 500. Nessun dato a rischio, le transazioni in corso
    # vengono chiuse pulite.
    say "    riavvio MySQL (pochi secondi di disservizio)..."
    run "systemctl restart mysql"
    echo "    mysql riavviato"
  fi
else
  echo "    $MYSQL_CONF non trovato, salto"
fi

# ------------------------------------------------------------------------------
say "3/4  Verifica"
if [ "$DRY_RUN" = "1" ]; then
  echo "    [dry-run] salto la verifica"
else
  sleep 3
  echo "    porte in ascolto:"
  ss -tln | grep -E ':(3306|6379)' | sed 's/^/      /' || echo "      (nessuna)"
  bad="$(ss -tln | awk '{print $4}' | grep -E '^(0\.0\.0\.0|\*|\[::\]):(3306|6379)$' || true)"
  if [ -n "$bad" ]; then
    echo "    ✗ ANCORA ESPOSTE: $bad" >&2
  else
    echo "    ✓ nessun bind pubblico su 3306/6379"
  fi
  echo -n "    redis: "; redis-cli -h 127.0.0.1 ping 2>&1 | head -1
  echo -n "    mysql: "; systemctl is-active mysql
  code="$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $SITE" http://127.0.0.1/ || echo "ERR")"
  echo "    app HTTP: $code"
  [ "$code" = "200" ] || [ "$code" = "302" ] || echo "    ⚠ il sito non risponde 200/302: controlla" >&2
fi

# ------------------------------------------------------------------------------
say "4/4  Utenti MySQL con host jolly (informativo, non li tocco)"
# forge@% e root@% accettano connessioni da qualsiasi host. Con il bind su
# localhost non sono piu' raggiungibili da fuori, quindi il rischio e' chiuso;
# restringerli e' comunque buona igiene, ma li lascio perche' Forge li usa per
# la funzione "accesso remoto al database" e toglierli a sorpresa la rompe.
if [ "$DRY_RUN" = "0" ]; then
  mysql -N -e "SELECT CONCAT('      ', user, '@', host) FROM mysql.user WHERE host IN ('%','0.0.0.0');" 2>/dev/null \
    || echo "      (non leggibile)"
  echo "    Per restringerli, quando vuoi:"
  echo "      DROP USER 'root'@'%';   -- se non usi l'accesso remoto da Forge"
fi

echo
echo "FATTO. Backup dei file: *.bak-$STAMP"
echo "Per tornare indietro:"
echo "  cp $REDIS_CONF.bak-$STAMP $REDIS_CONF && systemctl restart redis-server"
echo "  cp $MYSQL_CONF.bak-$STAMP $MYSQL_CONF && systemctl restart mysql"
