#!/bin/sh
#
# Runs certbot when the web app asks for a real certificate (a .request file in
# /acme/requests), then reloads nginx once the certificate changes. Also renews
# any certificate inside its renewal window, once a day.
last_renew=0

while :; do
  for req in /acme/requests/*.request; do
    [ -e "$req" ] || continue
    domain=$(basename "$req" .request)
    email=$(tr -d '[:space:]' < "$req")

    args="certonly --dns-cloudflare --dns-cloudflare-credentials /acme/cloudflare.ini -d $domain --non-interactive --agree-tos"
    if [ -n "$email" ]; then
      args="$args -m $email"
    else
      args="$args --register-unsafely-without-email"
    fi

    if certbot $args \
        --config-dir /acme/letsencrypt \
        --work-dir /acme/work \
        --logs-dir /acme/letsencrypt/logs \
        > /acme/results/$domain.log 2>&1; then
      cp /acme/letsencrypt/live/$domain/fullchain.pem /certs/fullchain.pem
      cp /acme/letsencrypt/live/$domain/privkey.pem /certs/privkey.pem
      echo "issued" > /acme/results/$domain.result
      nginx -s reload 2>/dev/null || true
    else
      tail -n 6 /acme/results/$domain.log > /acme/results/$domain.result 2>/dev/null \
        || echo "certbot failed" > /acme/results/$domain.result
    fi

    rm -f "$req"
  done

  now=$(date +%s)
  if [ $((now - last_renew)) -ge 86400 ]; then
    last_renew=$now
    if certbot renew \
        --config-dir /acme/letsencrypt \
        --work-dir /acme/work \
        --logs-dir /acme/letsencrypt/logs \
        >/dev/null 2>&1; then
      nginx -s reload 2>/dev/null || true
    fi
  fi

  sleep 5
done
