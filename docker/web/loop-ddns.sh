#!/bin/sh
#
# Once-a-minute dynamic DNS + notification check, supervised alongside FPM.
# Each pass is a fresh short-lived PHP process, so a wedged run costs one minute
# rather than a service. Runs as the pool user, not root: this writes logs into
# a bind mount, and root-owned files there would need sudo to read from the host.
sleep 15

while :; do
  su -s /bin/sh twocans -c \
    '/usr/local/bin/php /var/www/html/bin/ddns.php >> /var/log/php-fpm/ddns.log 2>&1' || true
  su -s /bin/sh twocans -c \
    '/usr/local/bin/php /var/www/html/bin/notify.php >> /var/log/php-fpm/notify.log 2>&1' || true
  sleep 60
done
