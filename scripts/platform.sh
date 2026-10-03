# twocans — knowing which Linux this is, and doing things its way: installing
# packages and Docker, starting services, and running things as root. Shared
# by ./install.sh and ./twocans; get.sh, run on its own from curl, keeps a
# small copy of what it needs.
#
# Sourced, not run, after scripts/ui.sh (it uses ok, warn, note and quietly).

# ---------------------------------------------------------------- which Linux
# From /etc/os-release, which every current distribution has:
#   OS_ID       ubuntu, debian, raspbian, fedora, rocky, arch, opensuse-leap, alpine…
#   OS_LIKE     its family, e.g. "ubuntu debian" for Linux Mint
#   OS_NAME     its full name, for messages: "Linux Mint 22"
#   OS_VERSION  its version number, e.g. 24.04
#   OS_CODENAME its release name (bookworm, noble…), and for an Ubuntu-based
#               one, Ubuntu's own (UBUNTU_CODENAME), which Docker's packages go by
OS_ID=""; OS_LIKE=""; OS_NAME=$(uname -s); OS_VERSION=""; OS_CODENAME=""; OS_UBUNTU_CODENAME=""
OS_RELEASE=${OS_RELEASE:-/etc/os-release}   # a test can point this elsewhere
if [[ -r "$OS_RELEASE" ]]; then
  # In a subshell, so its variables don't leak into ours.
  eval "$( . "$OS_RELEASE"
    printf 'OS_ID=%q OS_LIKE=%q OS_NAME=%q OS_VERSION=%q OS_CODENAME=%q OS_UBUNTU_CODENAME=%q\n' \
      "${ID:-}" "${ID_LIKE:-}" "${PRETTY_NAME:-${NAME:-Linux}}" "${VERSION_ID:-}" "${VERSION_CODENAME:-}" "${UBUNTU_CODENAME:-}" )"
fi

# A Synology runs DSM, which is Linux without a package manager: Docker comes
# from Package Center — Container Manager on DSM 7.2 and later, the "Docker"
# package before — its own web server holds ports 80 and 443, and Docker's
# socket is root's alone. /etc.defaults/VERSION says which DSM.
DSM=false; DSM_VERSION=""; DSM_MAJOR=0; DSM_MINOR=0; DSM_DOCKER_PKG=""
DSM_VERSION_FILE=${DSM_VERSION_FILE:-/etc.defaults/VERSION}   # a test can point this elsewhere
if [[ -r "$DSM_VERSION_FILE" ]] && grep -q '^productversion=' "$DSM_VERSION_FILE"; then
  DSM=true
  eval "$( . "$DSM_VERSION_FILE"
    printf 'DSM_VERSION=%q DSM_MAJOR=%q DSM_MINOR=%q\n' "${productversion:-}" "${majorversion:-0}" "${minorversion:-0}" )"
  OS_ID=dsm; OS_LIKE=""; OS_NAME="Synology DSM $DSM_VERSION"; OS_VERSION=$DSM_VERSION
  DSM_DOCKER_PKG=Docker
  (( DSM_MAJOR > 7 || (DSM_MAJOR == 7 && DSM_MINOR >= 2) )) && DSM_DOCKER_PKG=ContainerManager
  # Docker is in /usr/local/bin, which sudo and some logins leave off the path.
  [[ ":$PATH:" == *:/usr/local/bin:* ]] || PATH="/usr/local/bin:$PATH"
fi

# Package Center's name for the package that brings Docker, for messages.
dsm_docker_name() { [[ "$DSM_DOCKER_PKG" == ContainerManager ]] && echo "Container Manager" || echo "Docker"; }

# On a Synology, carry on as root: Docker's socket is root's, DSM has no docker
# group to join, and files made by one user and updated by another trip git
# up. Any administrator account can use sudo. Elsewhere, nothing.
dsm_as_root() {
  $DSM && [[ $EUID -ne 0 ]] || return 0
  # The package's setup container has no sudo; whether it can reach Docker
  # is the installer's own check to make.
  [[ "${TWOCANS_PACKAGE:-}" == 1 ]] && return 0
  command -v sudo >/dev/null 2>&1 || fail "On a Synology, twocans runs as root, and this account can't use sudo." \
    "Sign in over SSH as an administrator (a member of the administrators group)" \
    "Then run it again"
  note "On a Synology, Docker needs root, so this carries on with sudo (it may ask for your password)."
  exec sudo env "PATH=$PATH" bash "$0" "$@"
}

# Its family, for what to do: debian (apt), fedora/rhel (dnf), arch (pacman),
# suse (zypper), alpine (apk), or unknown.
os_family() {
  local all=" $OS_ID $OS_LIKE "
  case "$all" in
    *" dsm "*) echo dsm ;;
    *" alpine "*) echo alpine ;;
    *" arch "*|*" archlinux "*|*" manjaro "*) echo arch ;;
    *" suse "*|*" opensuse "*|*" sles "*|*" opensuse-leap "*|*" opensuse-tumbleweed "*) echo suse ;;
    *" fedora "*|*" rhel "*|*" centos "*|*" rocky "*|*" almalinux "*|*" ol "*|*" amzn "*) echo rhel ;;
    *" debian "*|*" ubuntu "*|*" raspbian "*) echo debian ;;
    *) echo unknown ;;
  esac
}
OS_FAMILY=$(os_family)

# --------------------------------------------------------------- package tools
PKG=""
if command -v apt-get >/dev/null 2>&1; then PKG=apt
elif command -v dnf >/dev/null 2>&1; then PKG=dnf
elif command -v yum >/dev/null 2>&1; then PKG=yum
elif command -v pacman >/dev/null 2>&1; then PKG=pacman
elif command -v zypper >/dev/null 2>&1; then PKG=zypper
elif command -v apk >/dev/null 2>&1; then PKG=apk
fi

# A package's name in this distribution, for what twocans needs from it.
pkg_name() {
  case "$1:$PKG" in
    iproute2:dnf|iproute2:yum) echo iproute ;;
    avahi:apt) echo "avahi-daemon libnss-mdns" ;;
    avahi:dnf|avahi:yum|avahi:pacman|avahi:zypper) echo "avahi nss-mdns" ;;
    avahi:apk) echo "avahi" ;;
    *) echo "$1" ;;
  esac
}

# The command to install packages here, as text, for messages and to run.
pkg_install_cmd() {
  local names="$*"
  case "$PKG" in
    apt) echo "${SUDO:+$SUDO }apt-get update -qq && ${SUDO:+$SUDO }apt-get install -y $names" ;;
    dnf) echo "${SUDO:+$SUDO }dnf install -y $names" ;;
    yum) echo "${SUDO:+$SUDO }yum install -y $names" ;;
    pacman) echo "${SUDO:+$SUDO }pacman -Syu --needed --noconfirm $names" ;;
    zypper) echo "${SUDO:+$SUDO }zypper --non-interactive install $names" ;;
    apk) echo "${SUDO:+$SUDO }apk add $names" ;;
    *) echo "" ;;
  esac
}

# ---------------------------------------------------------------- as root
# How to run something as root: nothing if we are root, sudo if there is one,
# doas if that's what's here. Empty ROOT_CMD with ROOT_OK=false: no way to.
SUDO=""; ROOT_OK=true
if [[ $EUID -ne 0 ]]; then
  if command -v sudo >/dev/null 2>&1; then SUDO=sudo
  elif command -v doas >/dev/null 2>&1; then SUDO=doas
  else ROOT_OK=false
  fi
fi

# Stop, kindly, when something needs root and there's no way to get it.
need_root() {
  $ROOT_OK && return 0
  fail "$1 needs administrator rights, and this machine has no sudo." \
    "Install sudo (as root: $(SUDO='' pkg_install_cmd sudo || echo 'your package manager'))" \
    "Add yourself to the sudo group (as root: usermod -aG sudo $(id -un), or 'wheel' on some systems)" \
    "Log out and back in, then run this again"
}

# --------------------------------------------------------------- services
# systemd almost everywhere; OpenRC on Alpine (and some others); plain
# 'service' scripts on a few older or smaller systems.
INIT=other
if $DSM; then INIT=dsm   # its services are Package Center's packages
elif [[ -d /run/systemd/system ]] && command -v systemctl >/dev/null 2>&1; then INIT=systemd
elif command -v rc-service >/dev/null 2>&1; then INIT=openrc
elif command -v service >/dev/null 2>&1; then INIT=sysv
fi

# Start a service, and have it start when the machine boots.
service_enable_now() {
  case "$INIT" in
    systemd) $SUDO systemctl enable --now "$1" ;;
    openrc) $SUDO rc-update add "$1" default >/dev/null && $SUDO rc-service "$1" start ;;
    sysv) $SUDO service "$1" start ;;
    dsm) [[ "$1" == docker ]] && $SUDO synopkg start "$DSM_DOCKER_PKG" >/dev/null ;;
    *) return 1 ;;
  esac
}

# Whether a service starts when the machine boots: yes, no, or unknown.
service_on_boot() {
  case "$INIT" in
    systemd) [[ "$(systemctl is-enabled "$1" 2>/dev/null || true)" == enabled ]] && echo yes || echo no ;;
    openrc) rc-update show default 2>/dev/null | grep -qw "$1" && echo yes || echo no ;;
    dsm) echo yes ;;   # a package that's running starts again when the Synology does
    *) echo unknown ;;
  esac
}

# The command to start a service, as text, for messages.
service_start_text() {
  case "$INIT" in
    systemd) echo "sudo systemctl enable --now $1" ;;
    openrc) echo "sudo rc-update add $1 default && sudo rc-service $1 start" ;;
    sysv) echo "sudo service $1 start" ;;
    dsm) [[ "$1" == docker ]] && echo "open Package Center and run $(dsm_docker_name) (or: sudo synopkg start $DSM_DOCKER_PKG)" \
           || echo "start the $1 service" ;;
    *) echo "start the $1 service" ;;
  esac
}

# ------------------------------------------------------------ 64-bit or not
# twocans' images are 64-bit only. A Raspberry Pi can run a 64-bit kernel
# under a 32-bit system (uname says aarch64, but Docker pulls 32-bit images),
# so the system's own word is asked too.
userland_bits() {
  local arch=""
  command -v dpkg >/dev/null 2>&1 && arch=$(dpkg --print-architecture 2>/dev/null || true)
  case "$arch" in armhf|armel|i386) echo 32; return ;; esac
  getconf LONG_BIT 2>/dev/null || echo 64
}

# ------------------------------------------------------------------ Docker
# Docker's own script (get.docker.com) knows only these. Others in their
# families — Linux Mint, Pop!_OS, Rocky, AlmaLinux… — are set up from
# Docker's own packages the way its documentation does by hand, and the rest
# from the distribution's own packages.
docker_install_plan() {
  $DSM && { echo dsm; return; }
  case "$OS_ID" in
    ubuntu|debian|raspbian|fedora|centos|rhel) echo getdocker; return ;;
  esac
  case "$OS_FAMILY" in
    debian)
      if [[ -n "$OS_UBUNTU_CODENAME" ]]; then echo "apt-repo ubuntu $OS_UBUNTU_CODENAME"
      elif [[ "$OS_CODENAME" =~ ^(bullseye|bookworm|trixie)$ ]]; then echo "apt-repo debian $OS_CODENAME"
      else echo distro; fi ;;
    rhel)
      if [[ "$OS_ID" == amzn ]]; then echo distro; else echo "dnf-repo centos"; fi ;;
    arch|suse|alpine) echo distro ;;
    *) echo none ;;
  esac
}

# A short description of how it'll be installed, for the question.
docker_install_text() {
  case "$(docker_install_plan)" in
    getdocker) echo "with Docker's official install script (get.docker.com)" ;;
    apt-repo*|dnf-repo*) echo "from Docker's own packages, set up for $OS_NAME" ;;
    distro) echo "from $OS_NAME's own packages" ;;
    dsm) echo "from Package Center ($(dsm_docker_name))" ;;
    *) echo "" ;;
  esac
}

# Install Docker and its compose plugin, the way that suits this Linux.
# Run through quietly(), so its output is kept aside unless it fails.
install_docker() {
  local plan base codename arch
  plan=$(docker_install_plan)
  case "$plan" in
    getdocker)
      local script; script=$(mktemp)
      curl -fsSL https://get.docker.com -o "$script" || return 1
      $SUDO sh "$script"; local rc=$?
      rm -f "$script"
      return $rc ;;
    apt-repo*)
      read -r _ base codename <<< "$plan"
      arch=$(dpkg --print-architecture)
      $SUDO apt-get update -qq \
        && $SUDO apt-get install -y ca-certificates curl \
        && $SUDO install -m 0755 -d /etc/apt/keyrings \
        && $SUDO curl -fsSL "https://download.docker.com/linux/$base/gpg" -o /etc/apt/keyrings/docker.asc \
        && $SUDO chmod a+r /etc/apt/keyrings/docker.asc \
        && echo "deb [arch=$arch signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/$base $codename stable" \
          | $SUDO tee /etc/apt/sources.list.d/docker.list >/dev/null \
        && $SUDO apt-get update -qq \
        && $SUDO apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin ;;
    dnf-repo*)
      read -r _ base <<< "$plan"
      $SUDO dnf install -y dnf-plugins-core \
        && { $SUDO dnf config-manager --add-repo "https://download.docker.com/linux/$base/docker-ce.repo" \
             || $SUDO dnf config-manager addrepo --from-repofile="https://download.docker.com/linux/$base/docker-ce.repo"; } \
        && $SUDO dnf install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin ;;
    dsm)
      $SUDO synopkg install_from_server "$DSM_DOCKER_PKG" && $SUDO synopkg start "$DSM_DOCKER_PKG" ;;
    distro)
      case "$PKG" in
        apt) $SUDO apt-get update -qq && $SUDO apt-get install -y docker.io \
               && { $SUDO apt-get install -y docker-compose-v2 || $SUDO apt-get install -y docker-compose-plugin || true; } ;;
        dnf|yum) $SUDO "$PKG" install -y docker ;;
        pacman) $SUDO pacman -Syu --needed --noconfirm docker docker-compose ;;
        zypper) $SUDO zypper --non-interactive install docker docker-compose ;;
        apk) $SUDO apk add docker docker-cli-compose ;;
        *) return 1 ;;
      esac ;;
    *) return 1 ;;
  esac
}

# The compose plugin on its own, for a Docker that came without it: the
# distribution's package if it has one, otherwise Docker's own release, put
# where Docker looks for plugins.
install_compose() {
  # A standalone docker-compose that's version 2 (a Synology's, say) is the
  # plugin already: it only needs to be where Docker looks.
  local standalone; standalone=$(command -v docker-compose 2>/dev/null || true)
  if [[ -n "$standalone" ]] && "$standalone" version 2>/dev/null | grep -qE 'v?2\.'; then
    mkdir -p "$HOME/.docker/cli-plugins" && ln -sf "$standalone" "$HOME/.docker/cli-plugins/docker-compose" \
      && docker compose version >/dev/null 2>&1 && return 0
  fi
  case "$PKG" in
    apt) $SUDO apt-get update -qq && { $SUDO apt-get install -y docker-compose-plugin || $SUDO apt-get install -y docker-compose-v2; } && return 0 ;;
    dnf|yum) $SUDO "$PKG" install -y docker-compose-plugin && return 0 ;;
    pacman) $SUDO pacman -Syu --needed --noconfirm docker-compose && return 0 ;;
    zypper) $SUDO zypper --non-interactive install docker-compose && return 0 ;;
    apk) $SUDO apk add docker-cli-compose && return 0 ;;
  esac
  local arch; arch=$(uname -m); [[ "$arch" == arm64 ]] && arch=aarch64
  $SUDO mkdir -p /usr/local/lib/docker/cli-plugins \
    && $SUDO curl -fsSL "https://github.com/docker/compose/releases/latest/download/docker-compose-linux-${arch}" \
         -o /usr/local/lib/docker/cli-plugins/docker-compose \
    && $SUDO chmod +x /usr/local/lib/docker/cli-plugins/docker-compose
}

# ------------------------------------------------------------------ ports
# The ports something is listening on, tcp or udp, one per line: ss where
# there is one, else netstat (all a Synology has).
listening_ports() {
  local t=${1:0:1}
  { if command -v ss >/dev/null 2>&1; then ss -Hln"$t" 2>/dev/null | awk '{print $4}'
    else netstat -ln"$t" 2>/dev/null | awk 'NR > 2 {print $4}'; fi
  } | sed -E 's/.*:([0-9]+)$/\1/' | grep -E '^[0-9]+$' | sort -un || true
}

# --------------------------------------------------------------- time zone
# This machine's time zone, as a name like Europe/London: what systemd or
# /etc/timezone says, else where /etc/localtime points. A Synology has only
# its own word for it (timezone="London" in /etc/synoinfo.conf), found among
# the zone names if it's there. Empty when none of that works.
detect_tz() {
  local tz="" syno
  tz=$(timedatectl show -p Timezone --value 2>/dev/null || true)
  [[ -n "$tz" ]] || tz=$(cat /etc/timezone 2>/dev/null || true)
  [[ -n "$tz" ]] || tz=$(readlink /etc/localtime 2>/dev/null | sed -n 's|.*zoneinfo/||p')
  if [[ -z "$tz" ]] && $DSM; then
    syno=$(sed -n 's/^timezone="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' /etc/synoinfo.conf 2>/dev/null | head -1)
    [[ -n "$syno" && -d /usr/share/zoneinfo ]] \
      && tz=$(cd /usr/share/zoneinfo && ls -d */"$syno" 2>/dev/null | grep -vE '^(posix|right)/' | head -1 || true)
  fi
  echo "$tz"
}
