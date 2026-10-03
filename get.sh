#!/usr/bin/env bash
#
# twocans in one line:
#
#   curl -fsSL https://raw.githubusercontent.com/tombruton87/TwoCans/main/get.sh | bash
#
# It asks where to put twocans (~/twocans unless you say), installs git if
# it's missing (asking first), clones twocans there and runs its installer —
# which checks everything, asks a few questions and starts it. If twocans is
# already in that folder, it updates it instead.
#
# Read it first if you like: https://github.com/tombruton87/TwoCans/blob/main/get.sh
#
# TWOCANS_DIR=/srv/twocans and TWOCANS_REPO=<git url> skip the questions.
#
set -euo pipefail

REPO=${TWOCANS_REPO:-https://github.com/tombruton87/TwoCans.git}

if [[ -t 1 ]]; then
  bold=$'\033[1m'; dim=$'\033[2m'; red=$'\033[31m'; green=$'\033[32m'; off=$'\033[0m'
  coral=$'\033[38;5;209m'; teal=$'\033[38;5;79m'
else
  bold=; dim=; red=; green=; off=; coral=; teal=
fi
ok()  { echo "  ${green}✓${off} $*"; }
# Stop, kindly: what went wrong, then what to do about it, step by step.
fail() {
  local problem=$1 i=1 step; shift
  {
    echo
    echo "  ${red}✗${off} ${bold}${problem}${off}"
    if (( $# > 0 )); then
      echo
      echo "    What to do:"
      for step in "$@"; do echo "      ${bold}${i}.${off} ${step}"; ((i++)); done
    fi
    echo
    echo "    ${dim}Stuck? Open an issue at${off} https://github.com/tombruton87/TwoCans/issues"
    echo
  } >&2
  exit 1
}
die() { fail "$*"; }
trap 'echo; echo "  ${red}✗${off} Something unexpected stopped this (line $LINENO). Run it again — if it stops in the same place, open an issue at https://github.com/tombruton87/TwoCans/issues" >&2' ERR

# Piped into bash, stdin is this script — so questions go to the terminal.
TTY=/dev/tty
[[ -r $TTY ]] && { : < $TTY; } 2>/dev/null || TTY=""
ask() {
  local answer
  if [[ -z "$TTY" ]]; then echo "$2"; return; fi
  read -r -p "  $1 [${bold}$2${off}]: " answer < "$TTY" || answer=""
  echo "${answer:-$2}"
}
confirm() {
  local answer
  [[ -z "$TTY" ]] && return 1
  read -r -p "  $1 [Y/n]: " answer < "$TTY" || answer=""
  [[ -z "$answer" || "$answer" =~ ^[Yy] ]]
}

echo
echo "  ${coral}${bold}two${off}${teal}${bold}cans${off}  ${dim}a tiny phone company, run by you${off}"
echo

[[ "$(uname -s)" == Linux ]] || fail "twocans runs on Linux, and this is $(uname -s)." \
  "Use a Linux machine — a Raspberry Pi 3, 4 or 5 is ideal, or any 64-bit PC running Ubuntu, Debian or similar" \
  "Docker Desktop on macOS and Windows can't carry phone calls reliably"
case "$(uname -m)" in
  x86_64|amd64|aarch64|arm64) ;;
  armv6l|armv7l|armhf) fail "This is a 32-bit system, and twocans needs a 64-bit one." \
    "On a Raspberry Pi 3, 4 or 5: put the 64-bit Raspberry Pi OS on its card (Raspberry Pi Imager → 'Raspberry Pi OS (64-bit)')" \
    "Then run this again" ;;
  *) fail "This machine's processor ($(uname -m)) isn't one twocans runs on." \
    "twocans needs a 64-bit Intel/AMD PC or a 64-bit ARM board, like a Raspberry Pi 3, 4 or 5" ;;
esac

# Root (a container or a minimal server, often): fine, without sudo.
SUDO=sudo
if [[ $EUID -eq 0 ]]; then
  SUDO=""
  echo "  ${dim}Running as root — fine. If this machine has a normal user, running it as them is tidier.${off}"
elif ! command -v sudo >/dev/null 2>&1; then
  SUDO=""
fi

# ------------------------------------------------------------------- where
DIR=${TWOCANS_DIR:-}
if [[ -z "$DIR" ]]; then
  DIR=$(ask "Where should twocans live?" "$([[ $EUID -eq 0 ]] && echo /opt/twocans || echo "$HOME/twocans")")
fi
DIR=${DIR/#\~/$HOME}

run_there() { if [[ -n "$TTY" ]]; then exec "$@" < "$TTY"; else exec "$@"; fi; }

if [[ -e "$DIR" ]]; then
  if [[ -x "$DIR/twocans" && -x "$DIR/install.sh" ]]; then
    ok "twocans is already in $DIR — updating it instead"
    cd "$DIR"
    run_there ./twocans update
  fi
  # An install from before ./twocans existed (0.1.1 or earlier): update it the
  # way ./twocans would — its Asterisk configs held passwords the old way, and
  # the installer writes those elsewhere now, so they're put back first.
  if [[ -x "$DIR/install.sh" && -f "$DIR/compose.yaml" && -d "$DIR/docker/asterisk" ]]; then
    ok "an older twocans is in $DIR — updating it instead"
    cd "$DIR"
    [[ -d .git ]] || fail "The twocans in $DIR wasn't downloaded with git, so it can't update itself." \
      "Move it aside: mv $DIR $DIR.old" "Run this again — your data can be copied across from $DIR.old/storage"
    git checkout -- docker/asterisk/etc/ari.conf docker/asterisk/etc/manager.conf docker/asterisk/etc/voicemail.conf 2>/dev/null || true
    changed=$(git status --porcelain --untracked-files=no)
    if [[ -n "$changed" ]]; then
      echo "  Files there have been changed by hand:"; echo "$changed" | sed 's/^/      /'
      fail "Some of twocans' own files in $DIR have been changed by hand (above), so it can't update safely." \
        "To undo the changes: cd $DIR && git checkout -- <file>" \
        "Or to keep them aside: cd $DIR && git stash" \
        "Then run this again"
    fi
    git pull -q --ff-only || fail "Couldn't update twocans from GitHub." \
      "Check this machine is online: ping -c1 github.com" "Then run this again"
    ok "updated to $(cat backend/VERSION 2>/dev/null || echo the latest)"
    echo
    run_there ./install.sh "$@"
  fi
  [[ -d "$DIR" && -z "$(ls -A "$DIR" 2>/dev/null)" ]] || fail "$DIR already has something else in it." \
    "Run this again and pick another folder" "Or empty $DIR first, if nothing in it is needed"
fi

# --------------------------------------------------------------------- git
if ! command -v git >/dev/null 2>&1; then
  echo "  git is needed, to fetch twocans and to update it later."
  install_git=""
  if command -v apt-get >/dev/null 2>&1; then install_git="$SUDO apt-get update -qq && $SUDO apt-get install -y -qq git"
  elif command -v dnf >/dev/null 2>&1; then install_git="$SUDO dnf install -y -q git"
  elif command -v yum >/dev/null 2>&1; then install_git="$SUDO yum install -y -q git"
  elif command -v pacman >/dev/null 2>&1; then install_git="$SUDO pacman -Syu --needed --noconfirm git"
  elif command -v zypper >/dev/null 2>&1; then install_git="$SUDO zypper -q install -y git"
  elif command -v apk >/dev/null 2>&1; then install_git="$SUDO apk add -q git"
  fi
  [[ -n "$install_git" ]] || fail "git isn't installed, and this doesn't know your package manager." \
    "Install git with your package manager" "Then run this again"
  [[ $EUID -eq 0 || -n "$SUDO" ]] || fail "git isn't installed, and installing it needs sudo, which this machine hasn't got." \
    "As root, install git: ${install_git# }" "Then run this again"
  confirm "Install git now?$([[ -n "$SUDO" ]] && echo ' (uses sudo)')" || fail "twocans needs git, to download it and to update it later." \
    "Install it: ${install_git}" "Then run this again"
  bash -c "$install_git" || fail "Installing git didn't work." \
    "Install it by hand: ${install_git}" "Then run this again"
  ok "git installed"
fi

# -------------------------------------------------------------------- fetch
echo "  fetching twocans…"
git clone -q "$REPO" "$DIR" || fail "Couldn't download twocans from GitHub." \
  "Check this machine is online: ping -c1 github.com" \
  "If it is, GitHub may be busy — wait a minute" \
  "Then run this again"
ok "twocans is in $DIR"
echo

# The installer takes it from here. Its questions need the terminal too.
cd "$DIR"
run_there ./install.sh "$@"
