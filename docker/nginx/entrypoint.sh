#!/bin/sh
#
# nginx entrypoint: a default self-signed certificate so https works out of the
# box, plus a small loop that runs certbot when the web app asks for a real one
# and reloads nginx once the certificate changes.
set -e

CERTS=/certs
ACME=/acme
mkdir -p "$CERTS" "$ACME/requests" "$ACME/results" "$ACME/challenge" "$ACME/letsencrypt" "$ACME/work"

# ------------------------------------------------------------------- default cert
if [ ! -f "$CERTS/fullchain.pem" ] || [ ! -f "$CERTS/privkey.pem" ]; then
  SAN="DNS:localhost,IP:127.0.0.1"

  for host in "${SIP_DOMAIN:-}" "${APP_URL:-}"; do
    [ -n "$host" ] || continue
    host="${host#*://}"; host="${host%%/*}"; host="${host%%:*}"
    [ -n "$host" ] || continue
    case "$host" in
      *[!0-9.]*) SAN="$SAN,DNS:$host" ;;
      *)         SAN="$SAN,IP:$host" ;;
    esac
  done

  openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
    -keyout "$CERTS/privkey.pem" -out "$CERTS/fullchain.pem" \
    -subj "/CN=twocans" -addext "subjectAltName=$SAN" >/dev/null 2>&1 || true
fi

# ------------------------------------------------------------- certbot watcher
(
  last_renew=0
  while :; do
    for req in "$ACME"/requests/*.request; do
      [ -e "$req" ] || continue
      domain=$(basename "$req" .request)
      email=$(tr -d '[:space:]' < "$req")

      args="certonly --dns-cloudflare --dns-cloudflare-credentials $ACME/cloudflare.ini -d $domain --non-interactive --agree-tos"
      if [ -n "$email" ]; then
        args="$args -m $email"
      else
        args="$args --register-unsafely-without-email"
      fi

      if certbot $args \
          --config-dir "$ACME/letsencrypt" \
          --work-dir "$ACME/work" \
          --logs-dir "$ACME/letsencrypt/logs" \
          > "$ACME/results/$domain.log" 2>&1; then
        cp "$ACME/letsencrypt/live/$domain/fullchain.pem" "$CERTS/fullchain.pem"
        cp "$ACME/letsencrypt/live/$domain/privkey.pem" "$CERTS/privkey.pem"
        echo "issued" > "$ACME/results/$domain.result"
        nginx -s reload 2>/dev/null || true
      else
        tail -n 6 "$ACME/results/$domain.log" > "$ACME/results/$domain.result" 2>/dev/null \
          || echo "certbot failed" > "$ACME/results/$domain.result"
      fi

      rm -f "$req"
    done

    # Renew any certificate inside its renewal window, once a day. certbot's
    # `renew` is a no-op until a cert is close to expiry, so this is cheap.
    now=$(date +%s)
    if [ $((now - last_renew)) -ge 86400 ]; then
      last_renew=$now
      if certbot renew \
          --config-dir "$ACME/letsencrypt" \
          --work-dir "$ACME/work" \
          --logs-dir "$ACME/letsencrypt/logs" \
          >/dev/null 2>&1; then
        nginx -s reload 2>/dev/null || true
      fi
    fi

    sleep 5
  done
) &

exec "$@"
