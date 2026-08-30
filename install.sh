#!/usr/bin/env bash
#
# twocans installer.
#
# Gets you a working install on your own network. It does not install Docker
# for you — doing that from a script is invasive, differs per distro and trips
# over existing installs — so it checks and tells you what to run instead.
#
#   ./install.sh              set up and start
#   ./install.sh --check      check prerequisites and ports, change nothing
#
set -euo pipefail

cd "$(dirname "$0")"
ENV_FILE=".env"
CHECK_ONLY=false
[[ "${1:-}" == "--check" ]] && CHECK_ONLY=true

bold=$'\033[1m'; red=$'\033[31m'; green=$'\033[32m'; yellow=$'\033[33m'; off=$'\033[0m'
ok()   { echo "  ${green}✓${off} $*"; }
warn() { echo "  ${yellow}!${off} $*"; }
die()  { echo "  ${red}✗${off} $*" >&2; exit 1; }

echo
echo "${bold}twocans${off} — a tiny phone company, run by you"
echo

# --------------------------------------------------------------- prerequisites
echo "${bold}Checking prerequisites${off}"

if ! command -v docker >/dev/null 2>&1; then
  echo "  ${red}✗${off} Docker is not installed."
  echo
  echo "    twocans deliberately does not install Docker for you. Install it"
  echo "    with your distribution's package manager, or follow:"
  echo "      https://docs.docker.com/engine/install/"
  echo
  echo "    Then run this script again."
  exit 1
fi
ok "docker $(docker --version | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)"

docker compose version >/dev/null 2>&1 || die "Docker Compose v2 is required (try: docker compose version)"
ok "docker compose $(docker compose version --short 2>/dev/null || echo v2)"

docker info >/dev/null 2>&1 || die "Cannot talk to the Docker daemon. Is it running, and are you in the 'docker' group?"
ok "docker daemon reachable"

# ------------------------------------------------------------------- addresses
echo
echo "${bold}Network${off}"

detect_ip() {
  # The address on the interface that reaches the internet — the one phones on
  # the same wifi will be able to reach.
  ip route get 1.1.1.1 2>/dev/null | grep -oP 'src \K[\d.]+' | head -1
}

EXISTING_SIP_DOMAIN=""
[[ -f "$ENV_FILE" ]] && EXISTING_SIP_DOMAIN=$(grep -E '^SIP_DOMAIN=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)

LAN_IP="${SIP_DOMAIN:-${EXISTING_SIP_DOMAIN:-$(detect_ip)}}"
[[ -n "$LAN_IP" ]] || die "Could not work out this machine's LAN address. Set it by hand: SIP_DOMAIN=192.168.1.10 ./install.sh"
ok "LAN address ${bold}${LAN_IP}${off} — phones will register here"

# ----------------------------------------------------------------------- ports
echo
echo "${bold}Ports${off}"

port_busy() {
  # ss is more reliable than lsof inside minimal images.
  ss -lntu 2>/dev/null | awk '{print $5}' | grep -qE "[:.]$1\$"
}

SIP_PORT="${SIP_PORT:-5060}"
RTP_START="${RTP_PORT_START:-10000}"
RTP_END="${RTP_PORT_END:-10100}"
HTTP_PORT="${HTTP_PORT:-8083}"

# On a machine that already has twocans up, the ports are held by our own
# containers, so checking them would only report ourselves as the problem.
if [[ -n "$(docker ps -q --filter 'name=^twocans-' 2>/dev/null)" ]]; then
  warn "twocans is already running — leaving its ports alone"
else
  FATAL_PORTS=false

  # Asterisk's ports cannot move: every SIP client defaults to 5060, and RTP
  # needs its range. Anything else holding them is a hard stop.
  if port_busy "$SIP_PORT"; then
    echo "  ${red}✗${off} port $SIP_PORT is in use — SIP cannot move off it"
    FATAL_PORTS=true
  else
    ok "SIP $SIP_PORT free"
  fi

  if port_busy "$RTP_START"; then
    echo "  ${red}✗${off} port $RTP_START is in use — RTP media needs ${RTP_START}-${RTP_END}"
    FATAL_PORTS=true
  else
    ok "RTP ${RTP_START}-${RTP_END} free"
  fi

  # The web port can move, so a clash just means picking another.
  if port_busy "$HTTP_PORT"; then
    for candidate in 8083 8084 8090 8100 8110 8120; do
      port_busy "$candidate" || { HTTP_PORT=$candidate; break; }
    done
    warn "web port was busy, using ${bold}${HTTP_PORT}${off} instead"
  else
    ok "web $HTTP_PORT free"
  fi

  $FATAL_PORTS && die "Free the ports above and run again. Asterisk cannot use different ones."
fi

if $CHECK_ONLY; then
  echo
  ok "All checks passed. Run ./install.sh to set up."
  exit 0
fi

# ------------------------------------------------------------------------- env
echo
echo "${bold}Configuration${off}"

secret() { head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n'; }

# ----------------------------------------------------------------- sounds
#
# The Asterisk image ships with no sound files whatsoever. Without these every
# stock prompt silently plays nothing — the blocked-call message, the voicemail
# prompts, "nobody is available" — and ConfBridge refuses to admit anyone to a
# group call at all, because it cannot open the file it wants to play them.
#
# ulaw rather than wav or gsm: it is what the phones actually use, so Asterisk
# plays it without transcoding, and the whole set is about 10MB.
SOUNDS_URL="https://downloads.asterisk.org/pub/telephony/sounds/asterisk-core-sounds-en-ulaw-current.tar.gz"

install_sounds() {
  local have
  have=$(docker compose exec -T asterisk sh -c \
    'ls /var/lib/asterisk/sounds/en/*.ulaw 2>/dev/null | wc -l' 2>/dev/null | tr -d '\r ')

  if [[ "${have:-0}" -gt 100 ]]; then
    ok "Asterisk prompts already installed (${have} files)"
    return 0
  fi

  echo "  fetching Asterisk's core sound prompts (~10MB)…"

  local tmp
  tmp=$(mktemp -d)
  if ! curl -fsSL --max-time 180 -o "$tmp/sounds.tar.gz" "$SOUNDS_URL"; then
    rm -rf "$tmp"
    warn "couldn't download the sound prompts — twocans will run, but spoken"
    warn "prompts and group calls will not work until they are installed"
    return 0
  fi

  mkdir -p "$tmp/en"
  tar xzf "$tmp/sounds.tar.gz" -C "$tmp/en"
  docker compose cp "$tmp/en/." asterisk:/var/lib/asterisk/sounds/en/ >/dev/null
  docker compose exec -T asterisk sh -c 'chown -R asterisk:asterisk /var/lib/asterisk/sounds' 2>/dev/null || true
  rm -rf "$tmp"

  ok "Asterisk prompts installed"
}

if [[ -f "$ENV_FILE" ]]; then
  ok ".env already exists — keeping your settings"
else
  cat > "$ENV_FILE" <<EOF
# Generated by install.sh on $(date +%Y-%m-%d). Keep this file private.

# Where phones register. Must be an address they can reach on your network —
# never localhost, and never a public hostname: SIP and RTP do not follow the
# web address.
SIP_DOMAIN=${LAN_IP}

# What a browser uses, and what the provisioning QR code points at. Change this
# to https://your.domain once you have a certificate.
APP_URL=http://${LAN_IP}:${HTTP_PORT}

HTTP_PORT=${HTTP_PORT}
TZ=$(timedatectl show -p Timezone --value 2>/dev/null || cat /etc/timezone 2>/dev/null || echo Europe/London)

# Phone numbers without a country code are assumed to be from here (44 = UK).
DEFAULT_COUNTRY_CODE=44

# Run the app as your user so bind-mounted files stay editable.
HOST_UID=$(id -u)
HOST_GID=$(id -g)

# --- secrets, generated once -------------------------------------------------
DB_NAME=twocans
DB_USER=twocans
DB_PASSWORD=$(secret)
DB_ROOT_PASSWORD=$(secret)

# Encrypts the SIP trunk's auth token at rest.
APP_KEY=$(secret)

# Asterisk control. Kept in sync with docker/asterisk/etc/{ari,manager}.conf.
ARI_USERNAME=twocans
ARI_PASSWORD=$(secret)
AMI_USERNAME=twocans
AMI_PASSWORD=$(secret)

# --- speech to text ----------------------------------------------------------
# Runs on this machine; audio never leaves it.
# base is quick; small is better on noisy phone audio at about 3x the time.
WHISPER_MODEL=base
WHISPER_LANGUAGE=en
WHISPER_THREADS=${WHISPER_THREADS:-4}
WHISPER_CPUS=${WHISPER_CPUS:-4.0}
WHISPER_MEMORY=${WHISPER_MEMORY:-2g}

# Only used by \`docker compose --profile tools up -d\`.
ADMINER_PORT=8089
EOF
  chmod 600 "$ENV_FILE"
  ok "wrote .env with fresh secrets"
fi

# Asterisk reads its own credentials from config files, so keep them matching.
set -a; . "./$ENV_FILE"; set +a
sed -i "s/^password = .*/password = ${ARI_PASSWORD}/" docker/asterisk/etc/ari.conf
sed -i "s/^secret = .*/secret = ${AMI_PASSWORD}/" docker/asterisk/etc/manager.conf
ok "Asterisk credentials synced"

mkdir -p docker/asterisk/{cdr,recordings,voicemail} docker/{nginx,php,mariadb}/log storage/{photos,jokes}
chmod 777 docker/asterisk/{cdr,recordings,voicemail} storage/photos storage/jokes 2>/dev/null || true
ok "data directories ready"

# ------------------------------------------------------------------- bring up
echo
echo "${bold}Starting${off} (first run builds images — this takes a few minutes)"
docker compose up -d --build

echo
echo "  waiting for the database…"
for _ in $(seq 1 60); do
  docker compose exec -T mariadb healthcheck.sh --connect >/dev/null 2>&1 && break
  sleep 2
done

install_sounds

docker compose exec -T php php /var/www/html/bin/migrate.php
# Writes SIP transports carrying this machine's address. Transports are not
# reloadable, so Asterisk is restarted rather than reloaded.
docker compose exec -T php php /var/www/html/bin/apply-config.php || true
docker compose restart asterisk >/dev/null

echo
echo "  ${green}✓${off} twocans is running"
echo
echo "    Open ${bold}${APP_URL}${off} and create your account."
echo
echo "    Phones register to ${bold}${SIP_DOMAIN}${off} and must be on the same"
echo "    network. Check everything is talking with:"
echo "      docker compose exec php php /var/www/html/bin/check-asterisk.php"
echo
