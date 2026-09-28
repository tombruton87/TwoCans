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
die() { echo "  ${red}✗${off} $*" >&2; exit 1; }

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

[[ "$(uname -s)" == Linux ]] || die "twocans runs on Linux (a Raspberry Pi is ideal)."
[[ $EUID -ne 0 ]] || die "Run this as your normal user, not root — it asks for sudo when it needs it."

# ------------------------------------------------------------------- where
DIR=${TWOCANS_DIR:-}
if [[ -z "$DIR" ]]; then
  DIR=$(ask "Where should twocans live?" "$HOME/twocans")
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
    [[ -d .git ]] || die "It isn't a git clone, so it can't update itself — move it aside and run this again."
    git checkout -- docker/asterisk/etc/ari.conf docker/asterisk/etc/manager.conf docker/asterisk/etc/voicemail.conf 2>/dev/null || true
    changed=$(git status --porcelain --untracked-files=no)
    if [[ -n "$changed" ]]; then
      echo "  Files there have been changed by hand:"; echo "$changed" | sed 's/^/      /'
      die "Undo them (git checkout -- <file>) or keep them (git stash), then run this again."
    fi
    git pull -q --ff-only || die "Couldn't update from GitHub — is this machine online?"
    ok "updated to $(cat backend/VERSION 2>/dev/null || echo the latest)"
    echo
    run_there ./install.sh "$@"
  fi
  [[ -d "$DIR" && -z "$(ls -A "$DIR" 2>/dev/null)" ]] || die "$DIR already has something else in it — pick another folder."
fi

# --------------------------------------------------------------------- git
if ! command -v git >/dev/null 2>&1; then
  echo "  git is needed, to fetch twocans and to update it later."
  install_git=""
  if command -v apt-get >/dev/null 2>&1; then install_git="sudo apt-get update -qq && sudo apt-get install -y -qq git"
  elif command -v dnf >/dev/null 2>&1; then install_git="sudo dnf install -y -q git"
  elif command -v yum >/dev/null 2>&1; then install_git="sudo yum install -y -q git"
  elif command -v pacman >/dev/null 2>&1; then install_git="sudo pacman -S --noconfirm git"
  elif command -v zypper >/dev/null 2>&1; then install_git="sudo zypper -q install -y git"
  elif command -v apk >/dev/null 2>&1; then install_git="sudo apk add -q git"
  fi
  [[ -n "$install_git" ]] || die "Install git with your package manager, then run this again."
  confirm "Install git now? (uses sudo)" || die "Install git, then run this again."
  bash -c "$install_git" || die "Installing git didn't work — install it by hand, then run this again."
  ok "git installed"
fi

# -------------------------------------------------------------------- fetch
echo "  fetching twocans…"
git clone -q "$REPO" "$DIR" || die "Couldn't fetch twocans from $REPO — is this machine online?"
ok "twocans is in $DIR"
echo

# The installer takes it from here. Its questions need the terminal too.
cd "$DIR"
run_there ./install.sh "$@"
