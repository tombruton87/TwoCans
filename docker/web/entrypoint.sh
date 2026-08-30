#!/bin/sh
#
# twocans web container. Single container that is the whole web server: it
# prompts PHP-FPM and nginx together under supervisord, runs the once-a-minute
# dynamic DNS check, and watches for certbot requests from the web app.
#
# A default self-signed certificate is generated here, before nginx starts, so
# https works the moment the container does; certbot replaces it with a real
# Let's Encrypt certificate when the household asks for one (see loop-certbot.sh).
set -e

CERTS=/certs
ACME=/acme
mkdir -p "$CERTS" "$ACME/requests" "$ACME/results" "$ACME/challenge" "$ACME/letsencrypt" "$ACME/work"

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

exec supervisord -c /etc/supervisord.conf
