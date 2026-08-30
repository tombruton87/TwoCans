#!/bin/sh
#
# twocans PHP container: FPM, plus the once-a-minute dynamic DNS check.
#
# Deliberately not a second container and not cron. The check is one database
# read and one HTTPS request; giving it its own service — or a cron daemon, or a
# supervisor — would be three more things to install, monitor and explain on a
# box that lives in somebody's house. Each pass is a fresh short-lived PHP
# process, so a wedged run costs one minute rather than a service.
#
# php-fpm stays PID 1 via exec, so signals, restarts and `docker exec` behave
# exactly as they did before this file existed.
set -e

# Only beside FPM. The transcriber runs from this same image with its own
# command, and must not start a second watcher. DDNS_WATCH=0 turns it off.
if [ "$1" = "php-fpm" ] && [ "${DDNS_WATCH:-1}" = "1" ]; then
  (
    # MariaDB is usually up (compose waits for healthy) but a cold start on a
    # slow box can still beat it, and there is no point logging that race.
    sleep 15

    while :; do
      # As the pool user, not root: this writes a log into a bind mount, and
      # root-owned files there would need sudo to read from the host. Absolute
      # path to php, because su does not promise /usr/local/bin is on PATH.
      su -s /bin/sh twocans -c \
        '/usr/local/bin/php /var/www/html/bin/ddns.php >> /var/log/php-fpm/ddns.log 2>&1' || true
      su -s /bin/sh twocans -c \
        '/usr/local/bin/php /var/www/html/bin/notify.php >> /var/log/php-fpm/notify.log 2>&1' || true
      sleep 60
    done
  ) &
fi

exec "$@"
