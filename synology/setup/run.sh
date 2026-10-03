#!/bin/bash
#
# twocans' Synology package, at work. Container Manager runs this (in the
# container setup/compose.yaml describes) each time the package starts. It
# puts this version's files in the twocans shared folder; sets twocans up
# there with ./install.sh, taking the install wizard's answers — or, when
# that's already done for this version, just starts it; then waits, and takes
# twocans down when the package stops. Its data stays, in the shared folder
# and Docker's volumes, for the next start.
#
# What it does goes in package.log, in the package's own folder (Package
# Center → twocans → View log) and in the shared folder (for File Station).
#
set -uo pipefail

APP=/var/packages/twocans/shares/twocans   # the shared folder, by DSM's own path to it
PKG=/pkg                                   # the package's files
VAR=/pkgvar                                # the package's own state
LOG=$VAR/package.log

# The log in the household's own time: the wizard's answer, or what .env says.
TZ=$(sed -n "s/^TWOCANS_TZ='\(.*\)'$/\1/p" "$VAR/answers.env" 2>/dev/null)
TZ=${TZ:-$(grep -E '^TZ=' "$APP/.env" 2>/dev/null | cut -d= -f2-)}
export TZ=${TZ:-UTC}

say() { echo "$(date '+%Y-%m-%d %H:%M:%S')  $*" | tee -a "$LOG"; }
set_state() { echo "$1" > "$VAR/state"; }   # for start-stop-status
publish_log() { cp "$LOG" "$APP/package.log" 2>/dev/null || true; }
env_get() { grep -E "^$1=" "$APP/.env" 2>/dev/null | tail -1 | cut -d= -f2- || true; }

# DSM's own record of twocans' ports — the main menu icon's address, and the
# firewall's list of applications — as .env has them now. DSM wrote both from
# the package before the wizard ran, so with the usual ports.
sync_dsm() {
  local http https sip trunk rtp_lo rtp_hi
  http=$(env_get HTTP_PORT); https=$(env_get HTTPS_PORT)
  sip=$(env_get SIP_PORT); trunk=$(env_get TRUNK_SIP_PORT)
  rtp_lo=$(env_get RTP_PORT_START); rtp_hi=$(env_get RTP_PORT_END)
  [[ -n "$http" ]] || return 0
  [[ -f /pkgui/config ]] && sed -i -E "s/(\"port\": *\")[0-9]+\"/\1$http\"/" /pkgui/config
  # Only the file DSM put there for the package is ever touched.
  local sc=""
  for sc in /dsm-etc/services.d/twocans.sc /dsm-etc/service.d/twocans.sc ""; do [[ -f "$sc" ]] && break; done
  if [[ -z "$sc" ]]; then
    say "DSM's firewall list has no twocans entries to update (no twocans.sc under /usr/local/etc)"
  else
    say "the firewall list's twocans entries: web $http, HTTPS ${https:-8443}, phones ${sip:-5060}, phone line ${trunk:-5062} (${sc#/dsm-etc/})"
    cat > "$sc" <<SC
[twocans_phones]
title="twocans: phones (SIP)"
desc="twocans"
port_forward="no"
dst.ports="${sip:-5060}/tcp,udp"

[twocans_line]
title="twocans: phone line (SIP)"
desc="twocans"
port_forward="yes"
dst.ports="${trunk:-5062}/udp"

[twocans_audio]
title="twocans: call audio (RTP)"
desc="twocans"
port_forward="yes"
dst.ports="${rtp_lo:-10000}:${rtp_hi:-10100}/udp"

[twocans_web]
title="twocans: web app"
desc="twocans"
port_forward="no"
dst.ports="${http}/tcp"

[twocans_https]
title="twocans: HTTPS"
desc="twocans"
port_forward="yes"
dst.ports="${https:-8443}/tcp"
SC
  fi
  say "DSM's main menu icon opens port $http"
}

# The last words of any twocans container that's stopped or unhealthy, for
# the log: what the installer's own message can't say.
container_report() {
  local name status
  for name in $(docker ps -a --filter name='^twocans-' --format '{{.Names}}' 2>/dev/null); do
    [[ "$name" == twocans-setup ]] && continue
    status=$(docker inspect -f '{{.State.Status}}{{if .State.Health}}/{{.State.Health.Status}}{{end}}' "$name" 2>/dev/null)
    [[ "$status" == running || "$status" == running/healthy ]] && continue
    say "── $name ($status), its last lines:"
    docker logs --tail 40 "$name" 2>&1 | sed 's/^/    /' | tee -a "$LOG"
  done
}

failed() {
  say "✗ $*"
  say "  Sort that out, then start twocans again: Package Center → twocans → Run."
  set_state failed
  publish_log
  exit 1
}

stop() {
  say "the package is stopping — taking twocans down (its data stays)"
  (cd "$APP" && docker compose down --remove-orphans) >> "$LOG" 2>&1
  say "twocans is stopped"
  set_state stopped
  publish_log
  exit 0
}
trap stop TERM INT

# Keep the log to its last few runs.
[[ -f "$LOG" ]] && tail -n 3000 "$LOG" > "$LOG.tmp" 2>/dev/null && mv "$LOG.tmp" "$LOG"

set_state starting
BUILD=$(cat "$PKG/build-id")
echo >> "$LOG"
say "package started: twocans $BUILD"

# Who this runs as, and whether Docker answers: what the rest depends on.
sock=$(stat -c 'owner %u:%g, mode %a' /var/run/docker.sock 2>/dev/null || echo "not there")
say "running as $(id -u):$(id -g) (groups $(id -G)); Docker's socket: $sock"
if docker_version=$(docker version --format '{{.Server.Version}}' 2>&1); then
  say "Docker answers: $docker_version"
else
  say "Docker doesn't answer: $docker_version"
  failed "The setup container can't use Docker. Container Manager ran it as $(id -u):$(id -g) — please send this log."
fi

[[ -d "$APP" && -w "$APP" ]] || failed "The twocans shared folder isn't there. DSM makes it when the package is installed — reinstalling the package should bring it back."

# The wizard's answers, when there are new ones; written by scripts/postinst.
if [[ -f "$VAR/answers.env" ]]; then
  set -a; . "$VAR/answers.env"; set +a
  say "using the answers from the install wizard"
fi
HOST_UID=${TWOCANS_HOST_UID:-$(env_get HOST_UID)}; HOST_UID=${HOST_UID:-1000}
HOST_GID=${TWOCANS_HOST_GID:-$(env_get HOST_GID)}; HOST_GID=${HOST_GID:-$HOST_UID}

# This version's files, owned as the app runs. Asterisk's voicemail.conf is
# the app's to write once it's there, so only a first one is put in place.
#
# A shared folder's permissions are DSM's (its ACLs), and DSM may refuse even
# root a change of mode or time on its folders — tar tries, to make a folder
# that looks unwritable writable. Those refusals change nothing that matters,
# so they're let pass; any other error isn't, and the files are checked after.
if [[ "$(cat "$APP/.package-files" 2>/dev/null)" != "$BUILD" ]]; then
  say "putting this version's files in the shared folder"
  errors=$(tar -C "$PKG/app" --exclude=./docker/asterisk/etc/voicemail.conf \
             --owner="$HOST_UID" --group="$HOST_GID" --numeric-owner -cf - . \
           | tar -C "$APP" --no-overwrite-dir --touch -xf - 2>&1)
  others=$(grep -vE 'Cannot (change mode|utime)|Exiting with failure status due to previous errors' <<< "$errors" || true)
  if [[ -n "$others" ]] || ! cmp -s "$PKG/app/install.sh" "$APP/install.sh" || ! cmp -s "$PKG/app/compose.yaml" "$APP/compose.yaml"; then
    echo "$errors" >> "$LOG"
    failed "Couldn't put twocans' files in the shared folder (above)."
  fi
  [[ -n "$errors" ]] && say "(DSM kept its own permissions on some folders, as it does — that's fine)"
  [[ -f "$APP/docker/asterisk/etc/voicemail.conf" ]] \
    || install $( (( EUID == 0 )) && echo "-o $HOST_UID -g $HOST_GID") -m 644 "$PKG/app/docker/asterisk/etc/voicemail.conf" "$APP/docker/asterisk/etc/voicemail.conf"
  echo "$BUILD" > "$APP/.package-files"
fi

cd "$APP" || failed "Couldn't open the twocans shared folder."

if [[ -f .env && ! -f "$VAR/answers.env" && "$(cat .package-setup 2>/dev/null)" == "$BUILD" ]]; then
  say "already set up for this version — starting twocans"
  docker compose up -d --remove-orphans 2>&1 | tee -a "$LOG"
  if (( PIPESTATUS[0] != 0 )); then
    container_report
    failed "twocans' containers wouldn't start (above)."
  fi
else
  say "setting twocans up with ./install.sh — the first time, about 3 GB of images download, so give it a while"
  publish_log
  # Into the log, and the container's own (Container Manager → Container → Log).
  TWOCANS_PACKAGE=1 bash ./install.sh --yes 2>&1 | tee -a "$LOG"
  if (( PIPESTATUS[0] != 0 )); then
    container_report
    failed "Setting twocans up didn't finish (above)."
  fi
  echo "$BUILD" > .package-setup
  [[ -f "$VAR/answers.env" ]] && mv "$VAR/answers.env" "$VAR/answers.applied"
fi

sync_dsm
set_state running
say "✓ twocans is running: $(env_get APP_URL)"
publish_log

# Until the package stops. Waiting on a child, so the stop is heard at once.
sleep infinity &
wait $!
