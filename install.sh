#!/usr/bin/env bash
#
# twocans installer — and updater.
#
# Clone the repo into a folder, then run this from inside it:
#
#   git clone https://github.com/tombruton87/TwoCans.git twocans && cd twocans
#   ./install.sh
#
#   ./install.sh                 set up (or update) and start
#   ./install.sh --check         check software, ports and firewall; change nothing
#   ./install.sh --yes           take every suggested answer, ask nothing
#   ./install.sh --reconfigure   ask the setup questions again (passwords are kept)
#   ./install.sh --no-start      write the configuration, but don't start anything
#   ./install.sh --write-secrets write Asterisk's password files from .env, and stop
#   ./install.sh --uninstall     stop and remove twocans (asks before deleting any data)
#   ./install.sh --reset-owner   reset the Owner account: password, email, passkeys
#
# To update later:  git pull && ./install.sh
#
# It does not install Docker for you — doing that from a script is invasive,
# differs per distro and trips over existing installs — so it checks, and tells
# you what to run instead. It only uses sudo for firewall rules, and asks first.
#
set -euo pipefail

cd "$(dirname "$0")"
ENV_FILE=".env"
SECRETS_DIR="docker/asterisk/etc/secrets"
TRANSPORTS="docker/asterisk/etc/generated/pjsip-transports.conf"

CHECK_ONLY=false; ASSUME_YES=false; RECONFIGURE=false; NO_START=false; SECRETS_ONLY=false; UNINSTALL=false; RESET_OWNER=false
for arg in "$@"; do
  case "$arg" in
    --check) CHECK_ONLY=true ;;
    --yes|-y) ASSUME_YES=true ;;
    --reconfigure) RECONFIGURE=true ;;
    --no-start) NO_START=true ;;
    --write-secrets) SECRETS_ONLY=true ;;
    --uninstall) UNINSTALL=true ;;
    --reset-owner) RESET_OWNER=true ;;
    -h|--help) sed -n '3,23p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Unknown option: $arg (try --help)" >&2; exit 2 ;;
  esac
done

source "$(dirname "$0")/scripts/ui.sh"

# ---------------------------------------------------------------- validators
is_ipv4() {
  [[ "$1" =~ ^([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})$ ]] || { echo "    That isn't an address like 192.168.1.10."; return 1; }
  local n; for n in "${BASH_REMATCH[@]:1}"; do (( n <= 255 )) || { echo "    That isn't an address like 192.168.1.10."; return 1; }; done
}
is_port() {
  [[ "$1" =~ ^[0-9]+$ ]] && (( $1 >= 1 && $1 <= 65535 )) || { echo "    A port is a number from 1 to 65535."; return 1; }
}
is_timezone() {
  [[ -f "/usr/share/zoneinfo/$1" ]] || { echo "    Not a timezone this machine knows — something like Europe/London."; return 1; }
}
is_country_code() {
  [[ "$1" =~ ^[1-9][0-9]{0,2}$ ]] || { echo "    Just the digits, without + or 00 — 44 for the UK, 1 for the US."; return 1; }
}
is_hostname() {
  [[ "$1" =~ ^[a-z0-9]([a-z0-9-]{0,38}[a-z0-9])?$ ]] || { echo "    Lower-case letters, digits and dashes — like twocans or smith-phones."; return 1; }
}
is_model() {
  [[ "$1" == base || "$1" == small ]] || { echo "    base or small."; return 1; }
}

# ------------------------------------------------------------------- .env
env_get() {
  [[ -f "$ENV_FILE" ]] || return 0
  grep -E "^$1=" "$ENV_FILE" 2>/dev/null | tail -1 | cut -d= -f2- || true
}

# Set a key in .env, keeping everything else in the file as it is.
env_set() {
  local key=$1 value=$2
  if grep -qE "^$key=" "$ENV_FILE" 2>/dev/null; then
    local escaped=${value//\\/\\\\}; escaped=${escaped//|/\\|}; escaped=${escaped//&/\\&}
    sed -i "s|^$key=.*|$key=$escaped|" "$ENV_FILE"
  else
    printf '%s=%s\n' "$key" "$value" >> "$ENV_FILE"
  fi
}

secret() { head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n'; }

# Asterisk reads its control passwords from files of its own. They live
# outside git (the tracked ari.conf and manager.conf #include them), so an
# update never conflicts with them and they never end up in the repository.
# Sets SECRETS_CHANGED.
write_secrets() {
  local before
  mkdir -p "$SECRETS_DIR"
  before=$(cat "$SECRETS_DIR"/*.conf 2>/dev/null | md5sum || true)
  ( umask 022
    printf '; Written by install.sh from ARI_PASSWORD in .env. Not in git.\npassword = %s\n' "$ARI_PASSWORD" > "$SECRETS_DIR/ari.conf"
    printf '; Written by install.sh from AMI_PASSWORD in .env. Not in git.\nsecret = %s\n' "$AMI_PASSWORD" > "$SECRETS_DIR/manager.conf" )
  # Explicitly: a folder with default ACLs ignores the umask and leaves them
  # writable by everyone. Asterisk only needs to read them.
  chmod 644 "$SECRETS_DIR/ari.conf" "$SECRETS_DIR/manager.conf"
  SECRETS_CHANGED=false
  [[ "$(cat "$SECRETS_DIR"/*.conf | md5sum)" != "$before" ]] && SECRETS_CHANGED=true
  return 0
}

# Reset the Owner account, in whichever twocans container runs the app — the
# single web container, or the separate PHP one of a development setup.
if $RESET_OWNER; then
  APP_CONTAINER=$(docker ps --format '{{.Names}}' 2>/dev/null | grep -xE 'twocans-(web|php)' | head -1 || true)
  [[ -n "$APP_CONTAINER" ]] || die "twocans isn't running — start it with ./install.sh first."
  TTY_FLAGS=(-i); [[ -t 0 && -t 1 ]] && TTY_FLAGS=(-it)
  exec docker exec "${TTY_FLAGS[@]}" "$APP_CONTAINER" php /var/www/html/bin/reset-owner.php
fi

if $SECRETS_ONLY; then
  [[ -f "$ENV_FILE" ]] || { echo "No .env here — run ./install.sh first." >&2; exit 1; }
  set -a; . "./$ENV_FILE"; set +a
  [[ -n "${ARI_PASSWORD:-}" && -n "${AMI_PASSWORD:-}" ]] || { echo ".env has no ARI_PASSWORD / AMI_PASSWORD." >&2; exit 1; }
  write_secrets
  echo "  Asterisk's password files are in $SECRETS_DIR/ ($($SECRETS_CHANGED && echo updated || echo unchanged))."
  exit 0
fi

# ================================================================= software
# A record of the run, for when something goes wrong: everything shown from
# here, without the colours and the spinner, in storage/reports/ — readable
# only by you, and picked up by ./twocans report. It never shows a password.
INSTALL_LOG=""
if mkdir -p storage/reports 2>/dev/null; then
  INSTALL_LOG="storage/reports/install-$(date +%Y%m%d-%H%M%S)$($CHECK_ONLY && echo -check || true).log"
  if : > "$INSTALL_LOG" 2>/dev/null; then
    chmod 600 "$INSTALL_LOG"
    { echo "twocans install.sh $* — $(date '+%Y-%m-%d %H:%M %Z') — twocans $(cat backend/VERSION 2>/dev/null)"; echo; } >> "$INSTALL_LOG"
    # awk rather than sed -u / grep --line-buffered, which BusyBox (Alpine)
    # lacks. The spinner frames are matched whole: some awks see bytes, not
    # characters, and a class of them would also hide lines starting with ✓.
    exec > >(tee >(awk '{
        gsub(/\033\[[0-9;]*[A-Za-z]/, "")
        n = split($0, part, "\r")
        for (i = 1; i <= n; i++)
          if (part[i] != "" || n == 1)
            if (part[i] !~ /^[[:space:]]*(⠋|⠙|⠹|⠸|⠼|⠴|⠦|⠧|⠇|⠏)/) print part[i]
        fflush()
      }' >> "$INSTALL_LOG")) 2>&1
  else
    INSTALL_LOG=""
  fi
fi

banner
$CHECK_ONLY && { echo; echo "  ${dim}Checking only: nothing will be changed.${off}"; }

section "Software"

if [[ "$(uname -s)" != Linux ]]; then
  warn "This is $(uname -s), not Linux."
  note "Docker Desktop on macOS and Windows can't pass phone calls (SIP and RTP)"
  note "through reliably. twocans is meant for a Linux machine or a Raspberry Pi."
  confirm "Carry on anyway?" n || exit 1
fi

# The published images come built for Intel/AMD and for ARM (a Raspberry Pi),
# and Docker pulls the one that fits. Only if a release lacks an ARM build are
# they built here, from the same Dockerfiles — checked once Docker is there.
BUILD_LOCALLY=false
ARM=false
case "$(uname -m)" in
  x86_64|amd64) ok "$(uname -m) — using the published images" ;;
  aarch64|arm64) ARM=true; ok "$(uname -m) (ARM)" ;;
  *) die "$(uname -m) isn't supported — twocans runs on 64-bit Intel/AMD or ARM." ;;
esac

MISSING=()
need() { command -v "$1" >/dev/null 2>&1 || MISSING+=("$1 ($2)"); }
need curl "curl"
need tar "tar"
need ss "iproute2"
need ip "iproute2"
need awk "gawk or mawk"
need od "coreutils"
if ((${#MISSING[@]})); then
  bad "Missing: ${MISSING[*]}"
  note "Install them with your package manager, e.g. sudo apt install curl iproute2"
  exit 1
fi
ok "curl, tar, ss and ip"
command -v git >/dev/null 2>&1 && ok "git — for updates (./twocans update)" \
  || warn "git isn't installed — you'll need it to update twocans later"

# Who is running this. $USER isn't set everywhere (cron, docker exec, some
# non-login shells), so ask the system.
ME=$(id -un)

# Carry on in this same session once we're in the docker group — a new group
# only reaches new logins, and nobody wants to log out halfway through.
rerun_with_docker_group() {
  note "Carrying on with your new docker group (it applies to new logins from now on)."
  exec sg docker -c "$(printf '%q ' "$0" "$@")"
}

if ! command -v docker >/dev/null 2>&1; then
  bad "Docker isn't installed."
  if ! $CHECK_ONLY && $CAN_PROMPT && [[ "$(uname -s)" == Linux ]] \
    && confirm "Install it now, with Docker's official script (get.docker.com)? (uses sudo)" y; then
    GET_DOCKER=$(mktemp)
    curl -fsSL https://get.docker.com -o "$GET_DOCKER" || die "Couldn't download Docker's install script — check this machine is online."
    note "This takes a few minutes; sudo may ask for your password first."
    sudo -v || die "sudo is needed to install Docker."
    quietly "Installing Docker" sudo sh "$GET_DOCKER"
    rm -f "$GET_DOCKER"
    sudo systemctl enable --now docker >/dev/null 2>&1 || true
    ok "Docker installed"
    if ! id -nG "$ME" | tr ' ' '\n' | grep -qx docker; then
      sudo usermod -aG docker "$ME" && ok "added $ME to the docker group"
      rerun_with_docker_group "$@"
    fi
  else
    note "Install it with Docker's official script, then run ./install.sh again:"
    note "  curl -fsSL https://get.docker.com | sudo sh"
    note "(or see https://docs.docker.com/engine/install/)"
    exit 1
  fi
fi
ok "docker $(docker --version | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)"

if ! docker compose version >/dev/null 2>&1; then
  bad "Docker Compose v2 (the 'docker compose' command) is missing."
  note "Docker from your distribution's own packages can lack it. Docker's official"
  note "script installs it: curl -fsSL https://get.docker.com | sudo sh"
  note "(on Ubuntu, 'sudo apt install docker-compose-v2' also works)"
  exit 1
fi
ok "docker compose $(docker compose version --short 2>/dev/null || echo v2)"

if ! docker info >/dev/null 2>&1; then
  if docker info 2>&1 | grep -qi "permission denied"; then
    bad "You don't have permission to use Docker."
    if ! $CHECK_ONLY && $CAN_PROMPT && confirm "Add yourself to the docker group? (uses sudo)" y; then
      sudo usermod -aG docker "$ME" && ok "added $ME to the docker group"
      rerun_with_docker_group "$@"
    fi
    note "To fix it: sudo usermod -aG docker \$USER — then log out and back in."
    exit 1
  fi
  bad "Docker isn't running."
  if ! $CHECK_ONLY && $CAN_PROMPT && confirm "Start it, and have it start on boot? (uses sudo)" y; then
    sudo systemctl enable --now docker >/dev/null 2>&1 || die "That didn't work — try: sudo systemctl start docker"
    wait_for "Waiting for Docker" 30 docker info || die "Docker didn't come up — try: sudo systemctl status docker"
  else
    note "Start it with: sudo systemctl enable --now docker"
    exit 1
  fi
fi
ok "docker is running and usable"

# On ARM: are there published ARM builds of twocans' own images? Older
# releases were Intel/AMD only; then (or offline) they're built here instead.
if $ARM; then
  has_arm() { docker manifest inspect "$1" 2>/dev/null | grep -q '"architecture": *"arm64"'; }
  if has_arm hamletdigital/twocans-web:latest && has_arm hamletdigital/twocans-whisper:latest; then
    ok "the published images include ARM builds"
  else
    BUILD_LOCALLY=true
    warn "no published ARM build to use, so the images will be built here"
    note "The first build takes a while (15–30 minutes on a Raspberry Pi)."
  fi
fi

COMPOSE=(docker compose)
$BUILD_LOCALLY && COMPOSE=(docker compose -f compose.yaml -f compose.build.yml)

# A development setup (compose.dev.yml: the code in this folder, live) stays
# one: built here, never swapped for the published images.
if [[ "$(docker inspect -f '{{.Config.Image}}' twocans-web 2>/dev/null || true)" == twocans-web:dev ]]; then
  COMPOSE=(docker compose -f compose.yaml -f compose.dev.yml)
  BUILD_LOCALLY=true
  note "development setup (compose.dev.yml) — building from this folder"
fi

# A firewall's rules are root's to read. Use sudo if it needs no password;
# otherwise ask first — it's read-only, but it may ask for yours.
SUDO_OK=false
can_sudo() {
  $SUDO_OK && return 0
  if sudo -n true 2>/dev/null; then SUDO_OK=true; return 0; fi
  $CAN_PROMPT || return 1
  confirm "Read the $1 rules with sudo? (read-only; it may ask for your password)" y || return 1
  sudo -v && SUDO_OK=true
}

# ============================================================== uninstall
#
# Stops twocans and removes its containers. Data — the database, recordings,
# voicemails, photos, backups, settings and passwords — is kept unless you type
# "delete". Only data is ever removed: the repo's own files stay, so the folder
# can be deleted by hand afterwards, or installed into again.
if $UNINSTALL; then
  section "Uninstall"
  $CAN_PROMPT || die "Uninstalling asks questions, so it needs a terminal (and not --yes)."

  echo "  This stops twocans and removes its containers."
  confirm "Go ahead?" n || { echo "  Nothing changed."; exit 0; }

  if [[ -n "$(docker ps -q --filter name=^twocans-asterisk$ 2>/dev/null)" ]]; then
    CALLS=$(docker exec twocans-asterisk asterisk -rx 'core show channels count' 2>/dev/null | grep -oE '^[0-9]+ active call' | grep -oE '^[0-9]+' || echo 0)
    if (( ${CALLS:-0} > 0 )); then
      warn "${CALLS} call(s) in progress — they'll be cut off"
      confirm "Carry on now?" n || { echo "  Nothing changed."; exit 0; }
    fi
  fi

  # Everything twocans keeps, inside this folder. Folder contents only, never
  # the files that come with the repo (.gitkeep, the config placeholders).
  DATA_DIRS=(
    storage/photos storage/jokes storage/refusals storage/pager storage/backups storage/exports storage/reports
    docker/asterisk/recordings docker/asterisk/voicemail docker/asterisk/cdr docker/asterisk/asks
    docker/asterisk/etc/generated docker/asterisk/etc/secrets
    docker/mariadb/log docker/php/log docker/nginx/log docker/nginx/certs docker/nginx/acme
  )
  echo
  warn "Deleting the data removes, for good: the call history and settings, every"
  note "recording and voicemail, photos, jokes, backups and exports (in storage/), and .env"
  note "with its passwords. Keep it, and ./install.sh later picks up where you left off."
  WIPE=false
  read -r -p "  Type ${bold}delete${off} to delete all of it, or press Enter to keep it: " answer < /dev/tty || answer=""
  [[ "$answer" == delete ]] && WIPE=true

  REMOVE_IMAGES=false
  confirm "Remove the downloaded images too? (frees about 3GB; they download again on install)" y && REMOVE_IMAGES=true

  # Firewall rules the installer added carry a "twocans" comment.
  UFW_RULES=()
  if command -v ufw >/dev/null 2>&1 && grep -qE '^ENABLED=yes' /etc/ufw/ufw.conf 2>/dev/null && can_sudo ufw; then
    mapfile -t UFW_RULES < <(sudo ufw status numbered 2>/dev/null | grep -E '# twocans' | grep -oE '^\[ *[0-9]+\]' | tr -d '[] ' | sort -rn)
    if ((${#UFW_RULES[@]})); then
      confirm "Remove the ${#UFW_RULES[@]} ufw rule(s) the installer added for twocans?" y || UFW_RULES=()
    fi
  fi

  # The .local name install.sh published.
  REMOVE_NAME=false
  if grep -q '^# twocans (install.sh)$' /etc/avahi/hosts 2>/dev/null; then
    confirm "Stop announcing its name on your network (in /etc/avahi/hosts)? (uses sudo)" y && REMOVE_NAME=true
  fi

  echo
  if $WIPE; then
    quietly "Stopping twocans and removing its volumes" "${COMPOSE[@]}" down -v --remove-orphans
  else
    quietly "Stopping twocans" "${COMPOSE[@]}" down --remove-orphans
  fi
  ok "containers removed"

  if $WIPE; then
    # Some of it belongs to the containers' users, so it's removed from inside
    # a container, which may; before the images go.
    WIPER=$(docker image ls -q hamletdigital/twocans-web 2>/dev/null | head -1)
    if [[ -n "$WIPER" ]]; then
      docker run --rm -v "$PWD:/w" --entrypoint sh "$WIPER" -c '
        for d in "$@"; do
          [ -d "/w/$d" ] && find "/w/$d" -mindepth 1 ! -name .gitkeep ! -name "*-00-placeholder.conf" -delete 2>/dev/null
        done
        rm -rf /w/docker/asterisk/etc/secrets' sh "${DATA_DIRS[@]}" || true
    else
      for d in "${DATA_DIRS[@]}"; do
        [[ -d "$d" ]] && find "$d" -mindepth 1 ! -name .gitkeep ! -name '*-00-placeholder.conf' -delete 2>/dev/null || true
      done
    fi
    rm -f "$ENV_FILE"
    ok "data deleted"
  fi

  if $REMOVE_IMAGES; then
    for image in $(grep -hoE '^\s*image:\s*\S+' compose.yaml | awk '{print $2}' | sort -u); do
      docker image rm "$image" >/dev/null 2>&1 || true
    done
    ok "images removed"
  fi

  if $REMOVE_NAME; then
    sudo sed -i '/^# twocans (install.sh)$/{N;d}' /etc/avahi/hosts \
      && { sudo systemctl reload avahi-daemon 2>/dev/null || true; } && ok "name no longer announced"
  fi

  if ((${#UFW_RULES[@]})); then
    for n in "${UFW_RULES[@]}"; do sudo ufw --force delete "$n" >/dev/null || warn "couldn't remove ufw rule $n"; done
    ok "firewall rules removed"
  fi

  echo
  if $WIPE; then
    box "twocans is uninstalled, and its data deleted" "" "You can delete this folder now:" "  rm -rf $PWD"
  else
    box "twocans is uninstalled" "" "Your data is kept - here and in Docker volumes." "Run ./install.sh to bring it back as it was."
  fi
  echo
  note "Ports your router opened for twocans by itself close within the hour;"
  note "any you forwarded by hand on the router are yours to remove."
  echo
  exit 0
fi

# Room to run: the stack is about 1GB of memory, speech-to-text up to 2GB more.
CPUS=$(nproc 2>/dev/null || echo 2)
MEM_MB=$(awk '/^MemTotal:/ {print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 0)
DISK_MB=$(df -Pm . | awk 'NR==2 {print $4}')
if (( MEM_MB > 0 && MEM_MB < 3000 )); then
  warn "${MEM_MB}MB of memory — speech-to-text will be tight; 4GB or more is comfortable"
else
  ok "${CPUS} CPU cores, $(( MEM_MB / 1024 ))GB memory"
fi
if (( DISK_MB < 5000 )); then
  warn "only $(( DISK_MB / 1024 ))GB free here — the images need about 3GB, and recordings grow"
else
  ok "$(( DISK_MB / 1024 ))GB free disk"
fi

# ================================================================ settings
FIRST_INSTALL=true
[[ -f "$ENV_FILE" ]] && FIRST_INSTALL=false
RUNNING=$(docker ps --format '{{.Names}}' 2>/dev/null | grep -c '^twocans-' || true)

detect_ip() { ip route get 1.1.1.1 2>/dev/null | grep -oE 'src [0-9.]+' | awk '{print $2}' | head -1 || true; }
detect_tz() { timedatectl show -p Timezone --value 2>/dev/null || cat /etc/timezone 2>/dev/null || echo Europe/London; }
country_for_tz() {
  case "$1" in
    Europe/London|Europe/Belfast) echo 44 ;; Europe/Dublin) echo 353 ;;
    America/*) echo 1 ;; Australia/*) echo 61 ;; Pacific/Auckland) echo 64 ;;
    Europe/Paris) echo 33 ;; Europe/Berlin) echo 49 ;; Europe/Madrid) echo 34 ;;
    Europe/Rome) echo 39 ;; Europe/Amsterdam) echo 31 ;; Europe/Brussels) echo 32 ;;
    Europe/Stockholm) echo 46 ;; Europe/Oslo) echo 47 ;; Europe/Copenhagen) echo 45 ;;
    *) echo 44 ;;
  esac
}

# What we'd suggest, starting from what .env already says.
LAN_IP=$(env_get SIP_DOMAIN); LAN_IP=${LAN_IP:-$(detect_ip)}
TZ_NAME=$(env_get TZ); TZ_NAME=${TZ_NAME:-$(detect_tz)}
COUNTRY=$(env_get DEFAULT_COUNTRY_CODE); COUNTRY=${COUNTRY:-$(country_for_tz "$TZ_NAME")}
HTTP_PORT=$(env_get HTTP_PORT); HTTP_PORT=${HTTP_PORT:-8083}
HTTPS_PORT=$(env_get HTTPS_PORT); HTTPS_PORT=${HTTPS_PORT:-443}
SIP_PORT=$(env_get SIP_PORT); SIP_PORT=${SIP_PORT:-5060}
TRUNK_SIP_PORT=$(env_get TRUNK_SIP_PORT); TRUNK_SIP_PORT=${TRUNK_SIP_PORT:-5062}
RTP_START=$(env_get RTP_PORT_START); RTP_START=${RTP_START:-10000}
RTP_END=$(env_get RTP_PORT_END); RTP_END=${RTP_END:-10100}
WHISPER_MODEL=$(env_get WHISPER_MODEL); WHISPER_MODEL=${WHISPER_MODEL:-base}
MDNS_NAME=$(env_get MDNS_NAME); MDNS_NAME=${MDNS_NAME:-twocans}

[[ -n "$LAN_IP" ]] || LAN_IP="192.168.1.10"

# ------------------------------------------------------------------ ports
# Who is listening where, gathered once. Our own running containers hold our
# ports on an update, so a port they publish isn't a clash.
LISTEN_TCP=$(ss -Hlnt 2>/dev/null | awk '{print $4}' | sed -E 's/.*:([0-9]+)$/\1/' | sort -un)
LISTEN_UDP=$(ss -Hlnu 2>/dev/null | awk '{print $4}' | sed -E 's/.*:([0-9]+)$/\1/' | sort -un)
OURS=$(docker ps --filter 'name=^twocans-' --format '{{.Ports}}' 2>/dev/null \
  | tr ',' '\n' | grep -oE ':[0-9]+(-[0-9]+)?->[0-9]+(-[0-9]+)?/(tcp|udp)' \
  | sed -E 's/^:([0-9]+)(-([0-9]+))?->[^/]*\/(.*)$/\4 \1 \3/' \
  | awk '{ last = ($3 == "" ? $2 : $3); for (p = $2; p <= last; p++) print $1 "/" p }' | sort -u || true)

# Everything but our own containers that is using proto/port.
in_use() {
  local proto=$1 port=$2 list
  grep -qx "$proto/$port" <<< "$OURS" && return 1
  [[ "$proto" == tcp ]] && list=$LISTEN_TCP || list=$LISTEN_UDP
  grep -qx "$port" <<< "$list"
}

# What is using it, when Docker can say.
holder() {
  local port=$1 name
  name=$(docker ps --format '{{.Names}} {{.Ports}}' 2>/dev/null | grep -E ":$port->" | awk '{print $1}' | head -1 || true)
  [[ -n "$name" ]] && echo " (by the container $name)" || echo ""
}

# The first free port from a list of candidates.
free_port() {
  local proto=$1; shift
  local p; for p in "$@"; do in_use "$proto" "$p" || { echo "$p"; return; }; done
  echo ""
}

check_ports() {
  PORT_PROBLEMS=0
  local p rtp_busy=""

  if in_use tcp "$HTTP_PORT"; then bad "web port $HTTP_PORT/tcp is in use$(holder "$HTTP_PORT")"; ((PORT_PROBLEMS++)) || true
  else ok "web $HTTP_PORT/tcp"; fi

  if in_use tcp "$HTTPS_PORT"; then bad "HTTPS port $HTTPS_PORT/tcp is in use$(holder "$HTTPS_PORT")"; ((PORT_PROBLEMS++)) || true
  else ok "HTTPS $HTTPS_PORT/tcp"; fi

  if in_use udp "$SIP_PORT" || in_use tcp "$SIP_PORT"; then bad "phones' SIP port $SIP_PORT is in use$(holder "$SIP_PORT")"; ((PORT_PROBLEMS++)) || true
  else ok "phones' SIP $SIP_PORT/udp+tcp"; fi

  if in_use udp "$TRUNK_SIP_PORT"; then bad "phone line's SIP port $TRUNK_SIP_PORT/udp is in use$(holder "$TRUNK_SIP_PORT")"; ((PORT_PROBLEMS++)) || true
  else ok "phone line's SIP $TRUNK_SIP_PORT/udp"; fi

  for ((p = RTP_START; p <= RTP_END; p++)); do in_use udp "$p" && { rtp_busy=$p; break; }; done
  if [[ -n "$rtp_busy" ]]; then bad "call audio needs ${RTP_START}–${RTP_END}/udp, and $rtp_busy is in use$(holder "$rtp_busy")"; ((PORT_PROBLEMS++)) || true
  else ok "call audio ${RTP_START}–${RTP_END}/udp"; fi

  # Our own ports must not collide with each other either.
  if [[ "$SIP_PORT" == "$TRUNK_SIP_PORT" ]]; then bad "the phones and the phone line can't share SIP port $SIP_PORT"; ((PORT_PROBLEMS++)) || true; fi
  if [[ "$HTTP_PORT" == "$HTTPS_PORT" ]]; then bad "web and HTTPS can't share port $HTTP_PORT"; ((PORT_PROBLEMS++)) || true; fi
  for p in "$HTTP_PORT" "$HTTPS_PORT" "$SIP_PORT" "$TRUNK_SIP_PORT"; do
    if (( p >= RTP_START && p <= RTP_END )); then bad "port $p is inside the call audio range ${RTP_START}–${RTP_END}"; ((PORT_PROBLEMS++)) || true; fi
  done
}

OTHER_WEB_PORT=""
# A web port someone typed: a number, free, and not one of twocans' others.
web_port_ok() {
  is_port "$1" || return 1
  if in_use tcp "$1"; then echo "    Port $1 is in use$(holder "$1") — pick another."; return 1; fi
  if [[ "$1" == "$SIP_PORT" || "$1" == "$TRUNK_SIP_PORT" ]] || (( $1 >= RTP_START && $1 <= RTP_END )); then
    echo "    twocans uses $1 for calls — pick another."; return 1
  fi
  if [[ "$1" == "$OTHER_WEB_PORT" ]]; then echo "    That's the other web port — pick another."; return 1; fi
  return 0
}

# The web and HTTPS ports can move: offer another when something holds one.
# The SIP ports and the call audio range can't — phones and your provider
# expect them, and Asterisk tells callers those exact ports — so a clash there
# is reported, and has to be freed.
resolve_ports() {
  local alt
  if in_use tcp "$HTTP_PORT"; then
    alt=$(free_port tcp 8083 8084 8090 8100 8180 8280 8380)
    warn "the web interface's port $HTTP_PORT is in use$(holder "$HTTP_PORT")"
    if confirm "Run the web interface on another port instead?" y; then
      OTHER_WEB_PORT=$HTTPS_PORT
      ask HTTP_PORT "Web interface port" "${alt:-8084}" web_port_ok
      $CAN_ASK && ok "the web interface will be on port $HTTP_PORT"
    fi
  fi
  if in_use tcp "$HTTPS_PORT"; then
    alt=$(free_port tcp 8443 9443 10443 11443)
    warn "the HTTPS port $HTTPS_PORT is in use$(holder "$HTTPS_PORT")"
    note "It can move; the port then goes in the address: https://your.name:${alt:-8443}"
    if confirm "Use another port for HTTPS instead?" y; then
      OTHER_WEB_PORT=$HTTP_PORT
      ask HTTPS_PORT "HTTPS port" "${alt:-8443}" web_port_ok
      $CAN_ASK && ok "HTTPS will be on port $HTTPS_PORT"
    fi
  fi
  return 0
}

# ------------------------------------------------------------- questions
if ! $CHECK_ONLY && { $FIRST_INSTALL || $RECONFIGURE; }; then
  section "A few questions"
  # Explanations only when there's someone to read them before answering.
  explain() { if $CAN_ASK; then echo; local line; for line in "$@"; do note "$line"; done; fi; }
  if $CAN_ASK; then
    note "Press Enter to take the suggestion in brackets."
  else
    note "Taking the suggested answers:"
  fi

  explain "The address phones reach this machine on, on your home network."
  ask LAN_IP "This machine's address" "$LAN_IP" is_ipv4
  if ! ip -4 -o addr show 2>/dev/null | grep -q " ${LAN_IP}/"; then
    warn "$LAN_IP isn't one of this machine's addresses — phones won't find it unless it forwards here"
  fi

  explain "A name for it on your home network, so browsers can find it as" \
          "http://<name>.local instead of an address. Change it if there's more than one."
  ask MDNS_NAME "Name on your network" "$MDNS_NAME" is_hostname

  explain "Bedtime and call hours follow this."
  ask TZ_NAME "Timezone" "$TZ_NAME" is_timezone

  explain "Numbers typed without a country code are taken to be from here."
  if ! $RECONFIGURE; then COUNTRY=$(country_for_tz "$TZ_NAME"); fi
  ask COUNTRY "Country calling code" "$COUNTRY" is_country_code

  explain "Speech-to-text writes down voicemails and calls, on this machine." \
          "base is quick; small copes better with noisy lines, at about 3x the time."
  ask WHISPER_MODEL "Speech-to-text model (base or small)" "$WHISPER_MODEL" is_model
fi

# ================================================================== ports
section "Ports"
if (( RUNNING > 0 )); then
  note "twocans is running — the ports its containers hold count as free."
fi
check_ports
# Only the web ports can be moved, so only a clash there is worth a second look.
if (( PORT_PROBLEMS > 0 )) && ! $CHECK_ONLY && { in_use tcp "$HTTP_PORT" || in_use tcp "$HTTPS_PORT"; }; then
  echo
  resolve_ports
  echo
  check_ports
fi
if (( PORT_PROBLEMS > 0 )); then
  sip_clash=false
  { in_use udp "$SIP_PORT" || in_use tcp "$SIP_PORT" || in_use udp "$TRUNK_SIP_PORT" \
    || [[ "$SIP_PORT" == "$TRUNK_SIP_PORT" ]]; } && sip_clash=true
  for ((p = RTP_START; p <= RTP_END; p++)); do in_use udp "$p" && { sip_clash=true; break; }; done
  if $sip_clash; then
    echo
    note "The SIP ports and the call audio range can't move: phones and your phone line"
    note "provider expect them, and Asterisk tells callers those exact ports. Stop whatever"
    note "is using them above (often another phone system, or an old twocans), then run again."
  fi
  if $CHECK_ONLY; then
    warn "Ports to sort out before installing — see above."
  else
    die "Free the ports above, then run ./install.sh again."
  fi
fi

# =============================================================== firewall
section "Firewall"

LAN_CIDR=$(ip -4 -o addr show 2>/dev/null | awk -v ip="$LAN_IP" '{split($4, a, "/"); if (a[1] == ip) print $4}' | head -1)
lan_network() {
  # 192.168.1.151/24 -> 192.168.1.0/24
  local addr=${1%/*} bits=${1#*/} a b c d mask n
  IFS=. read -r a b c d <<< "$addr"
  n=$(( (a << 24) | (b << 16) | (c << 8) | d ))
  mask=$(( bits == 0 ? 0 : (0xFFFFFFFF << (32 - bits)) & 0xFFFFFFFF ))
  n=$(( n & mask ))
  echo "$(( (n >> 24) & 255 )).$(( (n >> 16) & 255 )).$(( (n >> 8) & 255 )).$(( n & 255 ))/$bits"
}
LAN_NET=""
[[ -n "$LAN_CIDR" ]] && LAN_NET=$(lan_network "$LAN_CIDR")

# What twocans needs let in: label | proto | first port | last port | from.
# Phones and the web app only from the home network; the phone line, its audio
# and HTTPS from anywhere, since your provider (and you, away) come from outside.
FW_NEEDS=(
  "phones' SIP|udp|$SIP_PORT|$SIP_PORT|lan"
  "phones' SIP|tcp|$SIP_PORT|$SIP_PORT|lan"
  "web app|tcp|$HTTP_PORT|$HTTP_PORT|lan"
  "phone line's SIP|udp|$TRUNK_SIP_PORT|$TRUNK_SIP_PORT|any"
  "call audio|udp|$RTP_START|$RTP_END|any"
  "HTTPS|tcp|$HTTPS_PORT|$HTTPS_PORT|any"
)
ports_text() { [[ "$1" == "$2" ]] && echo "$1" || echo "$1–$2"; }


# Which sources a ufw ruleset lets reach proto ports lo..hi: "Anywhere",
# a list of networks, or MISSING when any port in the range isn't covered.
ufw_allowed() {
  local proto=$1 lo=$2 hi=$3
  awk -v proto="$proto" -v lo="$lo" -v hi="$hi" '
    function covers(to, p,   spec, pr, parts, k, r, a) {
      if (to == "Anywhere") return 1
      spec = to; pr = ""
      if (index(spec, "/")) { split(spec, a, "/"); spec = a[1]; pr = a[2] }
      if (pr != "" && pr != proto) return 0
      if (spec !~ /^[0-9,:]+$/) return 0
      k = split(spec, parts, ",")
      for (r = 1; r <= k; r++) {
        if (index(parts[r], ":")) { split(parts[r], a, ":"); if (p >= a[1] + 0 && p <= a[2] + 0) return 1 }
        else if (parts[r] + 0 == p) return 1
      }
      return 0
    }
    BEGIN { FS = "  +" }
    /^Default:/ && /allow \(incoming\)/ { open_all = 1 }
    /^--/ { rules = 1; next }
    !rules || NF < 3 { next }
    {
      to = $1; action = $2; from = $3
      if (to ~ /\(v6\)/ || from ~ /\(v6\)/) next
      if (action !~ /^ALLOW/ || action ~ /OUT/) next
      sub(/ *#.*$/, "", from)
      n++; To[n] = to; From[n] = from
    }
    END {
      if (open_all) { print "Anywhere"; exit }
      for (p = lo; p <= hi; p++) {
        hit = 0
        for (i = 1; i <= n; i++) if (covers(To[i], p)) { hit = 1; if (!(From[i] in seen)) { seen[From[i]] = 1; list = list (list == "" ? "" : ", ") From[i] } }
        if (!hit) { print "MISSING"; exit }
      }
      print list
    }'
}

FIREWALL=none
FW_STATUS=""          # the rules, when we could read them
FW_MISSING=()         # needs not covered, as FW_NEEDS entries
if command -v ufw >/dev/null 2>&1; then
  FIREWALL=ufw
  can_sudo ufw && FW_STATUS=$(sudo ufw status verbose 2>/dev/null || true)
  if [[ -n "$FW_STATUS" ]]; then
    grep -q "^Status: active" <<< "$FW_STATUS" || FIREWALL=ufw-off
  else
    # Couldn't read it: go by whether it's set to start.
    grep -qE '^ENABLED=yes' /etc/ufw/ufw.conf 2>/dev/null || FIREWALL=ufw-off
  fi
elif command -v firewall-cmd >/dev/null 2>&1 && systemctl is-active --quiet firewalld 2>/dev/null; then
  FIREWALL=firewalld
  can_sudo firewalld && FW_STATUS=$(sudo firewall-cmd --list-ports 2>/dev/null || true)
elif command -v nft >/dev/null 2>&1 && sudo -n nft list ruleset 2>/dev/null | grep -qE 'hook input.*policy drop'; then
  FIREWALL=nftables
fi

check_needs() {
  local need label proto lo hi from allowed spec
  for need in "${FW_NEEDS[@]}"; do
    IFS='|' read -r label proto lo hi from <<< "$need"
    spec="$(ports_text "$lo" "$hi")/$proto"
    if [[ "$FIREWALL" == ufw ]]; then
      allowed=$(ufw_allowed "$proto" "$lo" "$hi" <<< "$FW_STATUS")
    else
      # firewalld lists open ports as "5060/udp 10000-10100/udp"; open means from anywhere.
      allowed=MISSING
      grep -qw -- "$(ports_text "$lo" "$hi" | tr '–' '-')/$proto" <<< "$FW_STATUS" && allowed=Anywhere
      if [[ "$allowed" == MISSING && "$lo" == "$hi" ]]; then
        local r; for r in $FW_STATUS; do
          [[ "$r" =~ ^([0-9]+)-([0-9]+)/$proto$ ]] && (( lo >= BASH_REMATCH[1] && lo <= BASH_REMATCH[2] )) && allowed=Anywhere
        done
      fi
    fi
    if [[ "$allowed" == MISSING ]]; then
      bad "$label $spec — not allowed"
      FW_MISSING+=("$need")
    elif [[ "$allowed" == *Anywhere* || "$from" == lan ]]; then
      ok "$label $spec — allowed from ${allowed}"
    else
      warn "$label $spec — only from ${allowed}; your phone line comes from outside"
    fi
  done
}

# The commands that would let in what's missing.
fix_commands() {
  local need label proto lo hi from src
  FW_COMMANDS=()
  for need in "${FW_MISSING[@]}"; do
    IFS='|' read -r label proto lo hi from <<< "$need"
    if [[ "$FIREWALL" == ufw ]]; then
      src=any; [[ "$from" == lan && -n "$LAN_NET" ]] && src=$LAN_NET
      FW_COMMANDS+=("sudo ufw allow from $src to any port $([[ $lo == "$hi" ]] && echo "$lo" || echo "$lo:$hi") proto $proto comment 'twocans ${label//\'/}'")
    else
      FW_COMMANDS+=("sudo firewall-cmd --permanent --add-port=$([[ $lo == "$hi" ]] && echo "$lo" || echo "$lo-$hi")/$proto")
    fi
  done
  [[ "$FIREWALL" == firewalld && ${#FW_COMMANDS[@]} -gt 0 ]] && FW_COMMANDS+=("sudo firewall-cmd --reload")
  return 0
}

needs_text="$SIP_PORT udp+tcp, $TRUNK_SIP_PORT udp, ${RTP_START}–${RTP_END} udp, $HTTP_PORT and $HTTPS_PORT tcp"
case "$FIREWALL" in
  none)
    ok "no firewall found on this machine"
    note "If you run one this didn't spot, let in: $needs_text." ;;
  ufw-off)
    warn "ufw is installed but switched off — its rules aren't being applied"
    note "Nothing is blocked, so twocans works. If you switch it on (sudo ufw enable),"
    note "run ./install.sh --check again to see whether its rules let twocans in." ;;
  nftables)
    warn "nftables is dropping incoming traffic by default"
    note "This can't check nftables rules. Make sure phones can reach $SIP_PORT udp+tcp"
    note "and ${RTP_START}–${RTP_END} udp from ${LAN_NET:-your network}." ;;
  ufw|firewalld)
    if [[ -z "$FW_STATUS" ]]; then
      warn "$FIREWALL is on, but its rules couldn't be read without sudo"
      note "twocans needs: $needs_text."
      note "Run ./install.sh --check in a terminal to have it check them."
    else
      ok "$FIREWALL is on — checking its rules:"
      check_needs
      if (( ${#FW_MISSING[@]} > 0 )); then
        fix_commands
        note "Docker's published ports often get past $FIREWALL anyway, but not on every setup."
        note "To let them in:"
        for c in "${FW_COMMANDS[@]}"; do note "  $c"; done
        if ! $CHECK_ONLY && confirm "Add these rules now? (uses sudo)" y; then
          for c in "${FW_COMMANDS[@]}"; do
            eval "$c" >/dev/null || warn "that didn't work: $c"
          done
          ok "firewall rules added"
        fi
      fi
    fi ;;
esac
note "From outside the house, your router decides: see Phone line → Opening the router, in the app."

# ============================================================ this machine
section "This machine"

# 1. The address phones use has to stay put. One the router hands out (DHCP)
#    can change after a reboot or a power cut, and then every phone loses the line.
LAN_IF=$(ip -4 -o addr show 2>/dev/null | awk -v ip="$LAN_IP" '{split($4, a, "/"); if (a[1] == ip) print $2}' | head -1 || true)
if [[ -z "$LAN_IF" ]]; then
  warn "none of this machine's connections has $LAN_IP — phones won't find it"
elif ip -4 -o addr show dev "$LAN_IF" 2>/dev/null | grep " ${LAN_IP}/" | grep -q dynamic; then
  MAC=$(cat "/sys/class/net/$LAN_IF/address" 2>/dev/null || echo "")
  warn "$LAN_IP was handed out by your router, so it could change"
  note "If it does, every phone loses the line. On your router, reserve $LAN_IP for this"
  note "machine — usually called a DHCP reservation or static lease${MAC:+, for hardware address $MAC}."
  note "If you've already done that, you're fine."
else
  ok "$LAN_IP is set on this machine itself, so it won't change"
fi

# 2. Containers come back after a restart only if Docker itself starts at boot.
#    Socket activation isn't enough: it waits for someone to run docker.
if command -v systemctl >/dev/null 2>&1 && systemctl cat docker.service >/dev/null 2>&1; then
  DOCKER_BOOT=$(systemctl is-enabled docker.service 2>/dev/null || true)
  if [[ "$DOCKER_BOOT" == enabled ]]; then
    ok "Docker starts when the machine boots"
  else
    warn "Docker doesn't start when the machine boots — after a power cut the line stays down"
    if ! $CHECK_ONLY && confirm "Make Docker start on boot? (uses sudo)" y; then
      sudo systemctl enable docker.service >/dev/null 2>&1 && ok "Docker will start on boot" \
        || warn "that didn't work — try: sudo systemctl enable docker"
    else
      note "To fix it: sudo systemctl enable docker"
    fi
  fi
else
  note "Couldn't check whether Docker starts on boot (no systemd here) — make sure it does."
fi

# 3. Certificates and phone logins both fail when the clock drifts.
if command -v timedatectl >/dev/null 2>&1 && timedatectl show >/dev/null 2>&1; then
  if [[ "$(timedatectl show -p NTPSynchronized --value 2>/dev/null)" == yes ]]; then
    ok "the clock is kept in time automatically"
  elif [[ "$(timedatectl show -p NTP --value 2>/dev/null)" == yes ]]; then
    warn "clock syncing is on, but hasn't synced yet"
    note "If this doesn't clear, check this machine can reach the internet (it uses NTP)."
  else
    warn "the clock isn't kept in time — certificates and phone logins fail when it drifts"
    if ! $CHECK_ONLY && confirm "Keep the clock in time automatically? (uses sudo)" y; then
      sudo timedatectl set-ntp true && ok "clock syncing switched on" \
        || warn "that didn't work — try: sudo timedatectl set-ntp true"
    else
      note "To fix it: sudo timedatectl set-ntp true"
    fi
  fi
else
  note "Couldn't check the clock (no timedatectl here) — make sure it's kept in time."
fi

# 4. A name on the home network — http://twocans.local — published through
#    Avahi (the mDNS service most Linux machines run, a Raspberry Pi included)
#    as one line in /etc/avahi/hosts. Phones still use the address: SIP
#    doesn't look up .local names.
MDNS_FQDN="${MDNS_NAME}.local"
MDNS_URL="http://${MDNS_FQDN}$([[ "$HTTP_PORT" == 80 ]] || echo ":$HTTP_PORT")"
name_ips() { getent ahostsv4 "$1" 2>/dev/null | awk '{print $1}' | sort -u || true; }
publish_name() {
  sudo sh -c "sed -i '/^# twocans (install.sh)\$/{N;d}' /etc/avahi/hosts 2>/dev/null; printf '# twocans (install.sh)\n%s %s\n' '$LAN_IP' '$MDNS_FQDN' >> /etc/avahi/hosts" \
    && { sudo systemctl reload avahi-daemon 2>/dev/null || sudo avahi-daemon -r 2>/dev/null; }
}
AVAHI_ON=false
systemctl is-active --quiet avahi-daemon 2>/dev/null && AVAHI_ON=true
CAN_RESOLVE=false
grep -qE '^hosts:.*mdns' /etc/nsswitch.conf 2>/dev/null && CAN_RESOLVE=true

MDNS_OK=false
if grep -qx "$LAN_IP" <<< "$(name_ips "$MDNS_FQDN")"; then
  MDNS_OK=true
  ok "reachable by name as $MDNS_URL"
elif [[ -n "$(name_ips "$MDNS_FQDN")" ]]; then
  warn "$MDNS_FQDN is already another machine's ($(name_ips "$MDNS_FQDN" | head -1))"
  note "Pick another name with ./install.sh --reconfigure."
else
  if ! $AVAHI_ON && ! $CHECK_ONLY && command -v apt-get >/dev/null 2>&1 \
    && confirm "Install Avahi, so browsers can find twocans as $MDNS_FQDN? (uses sudo)" y; then
    quietly "Installing Avahi" sudo apt-get install -y avahi-daemon libnss-mdns
    sudo systemctl enable --now avahi-daemon >/dev/null 2>&1 || true
    systemctl is-active --quiet avahi-daemon 2>/dev/null && AVAHI_ON=true
    grep -qE '^hosts:.*mdns' /etc/nsswitch.conf 2>/dev/null && CAN_RESOLVE=true
  fi
  if ! $AVAHI_ON; then
    note "No Avahi here, so no .local name — use the address. (Avahi is the"
    note "avahi-daemon package; install it and run ./install.sh again for one.)"
  elif $CHECK_ONLY; then
    note "Not reachable as $MDNS_FQDN yet — ./install.sh will offer to set it up."
  elif confirm "Make it reachable as $MDNS_URL? (adds a line to /etc/avahi/hosts; uses sudo)" y; then
    if publish_name; then
      if ! $CAN_RESOLVE; then
        ok "published as $MDNS_FQDN — this machine can't look up .local names itself, so try it from a phone or laptop"
        MDNS_OK=true
      elif wait_for "Checking the name" 15 bash -c "getent ahostsv4 '$MDNS_FQDN' | grep -q '^$LAN_IP '"; then
        ok "reachable by name as $MDNS_URL"
        MDNS_OK=true
      else
        warn "published, but $MDNS_FQDN doesn't answer yet — give it a minute, then: ./twocans status"
      fi
    else
      warn "couldn't publish the name — the address still works"
    fi
  fi
fi

if $CHECK_ONLY; then
  section "Summary"
  if (( PORT_PROBLEMS > 0 )); then warn "Some ports need sorting out — see above."
  else ok "Everything checks out. Run ./install.sh to set up."; fi
  [[ -n "$INSTALL_LOG" ]] && note "A copy of this is in $INSTALL_LOG"
  exit 0
fi

# ============================================================ configuration
section "Configuration"

# Whose files the app's are: yours. Run as root (or through sudo), that's the
# person behind sudo, else 1000 — never root itself, which the app can't run as.
APP_UID=$(id -u); APP_GID=$(id -g)
if (( APP_UID == 0 )); then
  APP_UID=${SUDO_UID:-1000}; APP_GID=${SUDO_GID:-1000}
  (( APP_UID == 0 )) && { APP_UID=1000; APP_GID=1000; }
fi

if $FIRST_INSTALL; then
  cat > "$ENV_FILE" <<EOF
# Written by install.sh on $(date +%Y-%m-%d). Keep this file private.
# Change answers with ./install.sh --reconfigure (passwords are kept).

# Where phones register. An address they can reach on your network — never
# localhost, and never a public name: SIP and call audio don't follow the web.
SIP_DOMAIN=${LAN_IP}

# What a browser uses, and what phone setup links point at. Change it to
# https://your.name once you have a certificate.
APP_URL=http://${LAN_IP}:${HTTP_PORT}

HTTP_PORT=${HTTP_PORT}
HTTPS_PORT=${HTTPS_PORT}
TZ=${TZ_NAME}

# The name browsers can use on your home network: http://<this>.local — see
# /etc/avahi/hosts, which install.sh writes.
MDNS_NAME=${MDNS_NAME}

# Phone numbers typed without a country code are taken to be from here.
DEFAULT_COUNTRY_CODE=${COUNTRY}

# Phones register on SIP_PORT; your phone line provider reaches TRUNK_SIP_PORT.
# The call audio range can't move — Asterisk tells callers those exact ports.
SIP_PORT=${SIP_PORT}
TRUNK_SIP_PORT=${TRUNK_SIP_PORT}
RTP_PORT_START=${RTP_START}
RTP_PORT_END=${RTP_END}

# Run the app as your user so its files stay editable from here.
HOST_UID=${APP_UID}
HOST_GID=${APP_GID}

# --- passwords, made once ------------------------------------------------------
DB_NAME=twocans
DB_USER=twocans
DB_PASSWORD=$(secret)
DB_ROOT_PASSWORD=$(secret)

# Encrypts stored secrets like the phone line's token. Changing it makes them
# unreadable.
APP_KEY=$(secret)

# Asterisk control. install.sh writes these into ${SECRETS_DIR}/.
ARI_USERNAME=twocans
ARI_PASSWORD=$(secret)
AMI_USERNAME=twocans
AMI_PASSWORD=$(secret)

# --- speech to text ------------------------------------------------------------
# Runs on this machine; audio never leaves it.
WHISPER_MODEL=${WHISPER_MODEL}
WHISPER_LANGUAGE=en
WHISPER_THREADS=$(( CPUS > 4 ? 4 : CPUS ))
WHISPER_CPUS=$(( CPUS > 5 ? 4 : (CPUS > 1 ? CPUS - 1 : 1) )).0
WHISPER_MEMORY=2g

# Only used by \`docker compose --profile tools up -d\`.
ADMINER_PORT=8089
EOF
  chmod 600 "$ENV_FILE"
  ok "wrote .env with new passwords"
else
  OLD_SIP_DOMAIN=$(env_get SIP_DOMAIN)
  env_set SIP_DOMAIN "$LAN_IP"
  # Only move the web address along with the machine's address if it still
  # points at the old one — a domain someone set by hand stays.
  if [[ "$(env_get APP_URL)" == "http://${OLD_SIP_DOMAIN}:"* || -z "$(env_get APP_URL)" ]]; then
    env_set APP_URL "http://${LAN_IP}:${HTTP_PORT}"
  fi
  env_set HTTP_PORT "$HTTP_PORT"
  env_set HTTPS_PORT "$HTTPS_PORT"
  env_set TZ "$TZ_NAME"
  env_set DEFAULT_COUNTRY_CODE "$COUNTRY"
  env_set SIP_PORT "$SIP_PORT"
  env_set TRUNK_SIP_PORT "$TRUNK_SIP_PORT"
  env_set RTP_PORT_START "$RTP_START"
  env_set RTP_PORT_END "$RTP_END"
  env_set WHISPER_MODEL "$WHISPER_MODEL"
  env_set MDNS_NAME "$MDNS_NAME"
  # An older install run as root recorded 0, which the app can't run as.
  if [[ "$(env_get HOST_UID)" == 0 || -z "$(env_get HOST_UID)" ]]; then
    env_set HOST_UID "$APP_UID"; env_set HOST_GID "$APP_GID"
  fi
  # A password missing from an older .env is made now; existing ones never change.
  for key in DB_PASSWORD DB_ROOT_PASSWORD APP_KEY ARI_PASSWORD AMI_PASSWORD; do
    [[ -n "$(env_get "$key")" && "$(env_get "$key")" != change-me ]] || env_set "$key" "$(secret)"
  done
  chmod 600 "$ENV_FILE"
  ok "updated .env — your passwords are unchanged"
fi

set -a; . "./$ENV_FILE"; set +a

write_secrets
ok "Asterisk passwords in place"

mkdir -p docker/asterisk/{cdr,recordings,voicemail,asks} docker/{nginx,php,mariadb}/log storage/{photos,jokes,refusals,pager}
chmod 777 docker/asterisk/{cdr,recordings,voicemail,asks} storage/photos storage/jokes storage/refusals storage/pager 2>/dev/null || true
ok "data folders ready"

if $NO_START; then
  section "Done"
  ok "Configured. Start it with ./install.sh (or docker compose up -d)."
  exit 0
fi

# ================================================================== start
section "Containers"


# An update restarts the phone service; don't cut anyone off without asking.
if (( RUNNING > 0 )); then
  CALLS=$(docker exec twocans-asterisk asterisk -rx 'core show channels count' 2>/dev/null | grep -oE '^[0-9]+ active call' | grep -oE '^[0-9]+' || echo 0)
  if (( ${CALLS:-0} > 0 )); then
    warn "${CALLS} call(s) in progress — updating may cut them off"
    confirm "Carry on now?" n || die "Run ./install.sh again when the line is quiet."
  fi
fi

if $BUILD_LOCALLY; then
  quietly "Building the images for $(uname -m)" "${COMPOSE[@]}" build
else
  quietly "Fetching the latest images" "${COMPOSE[@]}" pull
fi
ok "images ready"

# The transports as Asterisk sees them — the file's header carries the time it
# was written, which changes every time and means nothing.
transports_sum() { grep -v '^;' "$TRANSPORTS" 2>/dev/null | md5sum || true; }
TRANSPORTS_BEFORE=$(transports_sum)
quietly "Starting the containers" "${COMPOSE[@]}" up -d --remove-orphans
ok "containers started"

wait_for "Waiting for the database" 120 "${COMPOSE[@]}" exec -T mariadb healthcheck.sh --connect \
  && ok "database ready" || warn "the database is slow to start — carrying on"

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
  local have tmp
  have=$("${COMPOSE[@]}" exec -T asterisk sh -c \
    'ls /var/lib/asterisk/sounds/en/*.ulaw 2>/dev/null | wc -l' 2>/dev/null | tr -d '\r ')
  if [[ "${have:-0}" -gt 100 ]]; then
    ok "Asterisk's spoken prompts are installed"
    return 0
  fi
  echo "  fetching Asterisk's spoken prompts (~10MB)…"
  tmp=$(mktemp -d)
  if ! curl -fsSL --max-time 180 -o "$tmp/sounds.tar.gz" "$SOUNDS_URL"; then
    rm -rf "$tmp"
    warn "couldn't download the spoken prompts — twocans runs, but prompts and"
    warn "group calls won't work until ./install.sh is run again with internet"
    return 0
  fi
  mkdir -p "$tmp/en"
  tar xzf "$tmp/sounds.tar.gz" -C "$tmp/en"
  "${COMPOSE[@]}" cp "$tmp/en/." asterisk:/var/lib/asterisk/sounds/en/ >/dev/null 2>&1
  "${COMPOSE[@]}" exec -T asterisk sh -c 'chown -R asterisk:asterisk /var/lib/asterisk/sounds' 2>/dev/null || true
  rm -rf "$tmp"
  ok "Asterisk's spoken prompts installed"
}
install_sounds

# Music on hold: without it, a phone putting someone on hold leaves them in
# silence. Asterisk's own Opsound set (CC-BY-SA), about 2MB; see
# docker/asterisk/etc/musiconhold.conf.
MOH_URL="https://downloads.asterisk.org/pub/telephony/sounds/asterisk-moh-opsound-ulaw-current.tar.gz"

install_moh() {
  local have tmp
  have=$("${COMPOSE[@]}" exec -T asterisk sh -c \
    'ls /var/lib/asterisk/moh/*.ulaw 2>/dev/null | wc -l' 2>/dev/null | tr -d '\r ')
  if [[ "${have:-0}" -gt 0 ]]; then
    ok "music on hold is installed"
    return 0
  fi
  echo "  fetching music on hold (~2MB)…"
  tmp=$(mktemp -d)
  if ! curl -fsSL --max-time 120 -o "$tmp/moh.tar.gz" "$MOH_URL"; then
    rm -rf "$tmp"
    warn "couldn't download music on hold — calls on hold are silent until ./install.sh is run again with internet"
    return 0
  fi
  mkdir -p "$tmp/moh"
  tar xzf "$tmp/moh.tar.gz" -C "$tmp/moh"
  "${COMPOSE[@]}" exec -T asterisk mkdir -p /var/lib/asterisk/moh >/dev/null 2>&1 || true
  "${COMPOSE[@]}" cp "$tmp/moh/." asterisk:/var/lib/asterisk/moh/ >/dev/null 2>&1
  "${COMPOSE[@]}" exec -T asterisk sh -c 'chown -R asterisk:asterisk /var/lib/asterisk/moh; asterisk -rx "module load res_musiconhold.so" >/dev/null 2>&1; asterisk -rx "moh reload" >/dev/null 2>&1' 2>/dev/null || true
  rm -rf "$tmp"
  ok "music on hold installed"
}
install_moh

quietly "Updating the database" "${COMPOSE[@]}" exec -T web php /var/www/html/bin/migrate.php
ok "database up to date"

# Writes the SIP config for this machine's address and ports. Transports
# aren't reloadable, so Asterisk restarts only when they (or its passwords)
# changed — a restart drops calls, and an ordinary update shouldn't.
"${COMPOSE[@]}" exec -T web php /var/www/html/bin/apply-config.php >/dev/null 2>&1 || true
if $FIRST_INSTALL || $SECRETS_CHANGED || [[ "$(transports_sum)" != "$TRANSPORTS_BEFORE" ]]; then
  "${COMPOSE[@]}" restart asterisk >/dev/null 2>&1
  sleep 3
  "${COMPOSE[@]}" exec -T web php /var/www/html/bin/apply-config.php >/dev/null 2>&1 || true
  ok "phone service restarted with the new settings"
else
  ok "phone service settings unchanged"
fi

web_up() { [[ "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${HTTP_PORT}/" || true)" =~ ^(200|302)$ ]]; }
if wait_for "Waiting for the web app" 60 web_up; then ok "web app answering"
else warn "the web app isn't answering yet — check: docker compose logs web"; fi

# ================================================================= done

echo
OPEN_AT=("Open $APP_URL")
$MDNS_OK && OPEN_AT=("Open $MDNS_URL" "  (or $APP_URL)")
if $FIRST_INSTALL; then
  box "twocans is running" "" "${OPEN_AT[@]}" "and create your account."
else
  box "twocans is up to date and running" "" "${OPEN_AT[@]}"
fi
echo
echo "  Phones register to ${bold}${SIP_DOMAIN}:${SIP_PORT}${off} and must be on the same network."
if [[ "$APP_URL" == http://* ]]; then
  echo "  Face ID sign-in, and adding twocans to a phone's home screen, need HTTPS: give it"
  echo "  an address and certificate under Phone line → Where the outside world finds you."
fi
echo "  For calls from a phone line, the router must let in ${TRUNK_SIP_PORT}/udp and"
echo "  ${RTP_START}–${RTP_END}/udp — see Phone line → Opening the router, in the app."
echo
echo "  ${dim}Is everything working?${off}  ./twocans status"
echo "  ${dim}Update later with:${off}       ./twocans update"
echo "  ${dim}Everything else:${off}         ./twocans help"
[[ -n "$INSTALL_LOG" ]] && echo "  ${dim}A record of this install:${off} $INSTALL_LOG"
echo
