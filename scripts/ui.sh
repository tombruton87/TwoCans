# twocans — the look shared by ./install.sh and ./twocans: the logo's colours,
# status lines, section rules, the banner, boxes, the spinner, questions, and
# running a slow step quietly.
#
# Sourced, not run. Set ASSUME_YES and CHECK_ONLY (true/false) before sourcing
# if they apply: --yes means nothing is asked, and --check asks only what
# changes nothing.

ASSUME_YES=${ASSUME_YES:-false}
CHECK_ONLY=${CHECK_ONLY:-false}

# ------------------------------------------------------------------- output
# The logo's colours where the terminal has them: coral and teal, a lighter
# shade for each can's lid and a darker one for its ribs, and a tan string.
TTY=false; [[ -t 1 ]] && TTY=true
COLORS=0; $TTY && COLORS=$(tput colors 2>/dev/null || echo 8)
if $TTY; then
  bold=$'\033[1m'; dim=$'\033[2m'; red=$'\033[31m'; green=$'\033[32m'; yellow=$'\033[33m'; off=$'\033[0m'
  if (( COLORS >= 256 )); then
    coral=$'\033[38;5;209m'; coral_lid=$'\033[38;5;216m'; coral_rib=$'\033[38;5;173m'
    teal=$'\033[38;5;79m';  teal_lid=$'\033[38;5;122m';  teal_rib=$'\033[38;5;36m'
    tan=$'\033[38;5;180m'
  else
    coral=$'\033[31m'; coral_lid=$'\033[91m'; coral_rib=$'\033[31m'
    teal=$'\033[36m';  teal_lid=$'\033[96m';  teal_rib=$'\033[36m'
    tan=$'\033[33m'
  fi
else
  bold=; dim=; red=; green=; yellow=; off=
  coral=; coral_lid=; coral_rib=; teal=; teal_lid=; teal_rib=; tan=
fi
ok()      { echo "  ${green}✓${off} $*"; }
warn()    { echo "  ${yellow}!${off} $*"; }
bad()     { echo "  ${red}✗${off} $*"; }
note()    { echo "    ${dim}$*${off}"; }
die()     { bad "$*" >&2; help_footer >&2; exit 1; }

# Where to turn when it can't be fixed here.
ISSUES_URL="https://github.com/tombruton87/TwoCans/issues"
help_footer() {
  echo
  echo "    ${dim}Stuck? ${off}./twocans report${dim} makes a report with private details taken out —${off}"
  echo "    ${dim}attach it to an issue at ${off}${ISSUES_URL}"
  [[ -n "${INSTALL_LOG:-}" ]] && echo "    ${dim}A record of this run is in ${INSTALL_LOG}${off}"
  echo
}

# Stop, kindly: what went wrong, then what to do about it, step by step.
#   fail "Docker isn't running." "Start it: sudo systemctl start docker" "Run ./install.sh again"
fail() {
  local problem=$1; shift
  {
    echo
    bad "${bold}${problem}${off}"
    if (( $# > 0 )); then
      echo
      echo "    What to do:"
      local i=1 step
      for step in "$@"; do
        # The Synology package runs it again from Package Center, not a terminal.
        [[ "${TWOCANS_PACKAGE:-}" == 1 ]] && step=${step//run .\/install.sh again/start twocans again in Package Center (twocans → Run)}
        echo "      ${bold}${i}.${off} ${step}"
        ((i++))
      done
    fi
    help_footer
  } >&2
  exit 1
}

# Anything that stops the script that it didn't expect: say so plainly, with
# what to try, rather than stopping without a word. Only in the script itself
# — a check inside $(…) that fails is the script's to handle.
STEP=""
unexpected_stop() {
  local status=$1 line=$2 command=$3
  # Only where a failure stops the script (set -e), and only in the script itself.
  [[ $- == *e* ]] && (( BASH_SUBSHELL == 0 )) || return 0
  trap - ERR
  {
    echo
    bad "${bold}Something unexpected stopped this${STEP:+, during "$STEP"}.${off}"
    note "(line $line: ${command:0:120} — exit $status)"
    echo
    echo "    What to do:"
    echo "      ${bold}1.${off} Run it again — it picks up where it left off, and nothing's left half-done"
    echo "      ${bold}2.${off} If it stops in the same place, see the record of this run below"
    help_footer
  } >&2
}
trap 'unexpected_stop $? $LINENO "$BASH_COMMAND"' ERR
set -E

# ── Section ─────────────────────────────────── in teal, ruled to one width.
section() {
  STEP=$*
  local title="── $* " rule=""
  while (( ${#title} + ${#rule} < 64 )); do rule+="─"; done
  echo
  echo "${teal}${bold}${title}${rule}${off}"
}

# The two cans on their string, and the wordmark: "two" in coral, "cans" in teal.
banner() {
  local string="" i
  for ((i = 0; i < 15; i++)); do string+=" ·"; done
  string+=" "
  local gap; printf -v gap '%31s' ''
  echo
  echo "      ${coral_lid}▄▄▄▄▄▄▄▄${off}${gap}${teal_lid}▄▄▄▄▄▄▄▄${off}"
  echo "      ${coral}████████${off}${gap}${teal}████████${off}"
  echo "      ${coral_rib}▓▓▓▓▓▓▓▓${off}${gap}${teal_rib}▓▓▓▓▓▓▓▓${off}"
  echo "      ${coral}████████${off}${tan}${string}${off}${teal}████████${off}"
  echo "      ${coral_rib}▓▓▓▓▓▓▓▓${off}${gap}${teal_rib}▓▓▓▓▓▓▓▓${off}"
  echo "      ${coral}████████${off}${gap}${teal}████████${off}"
  echo "      ${coral}▀▀▀▀▀▀▀▀${off}${gap}${teal}▀▀▀▀▀▀▀▀${off}"
  echo
  local two=(
    ' _                     '
    '| |_ __      __   ___  '
    '| __|\ \ /\ / /  / _ \ '
    '| |_  \ V  V /  | (_) |'
    ' \__|  \_/\_/    \___/ '
  )
  local cans=(
    '                         '
    '  ___   __ _  _ __   ___ '
    ' / __| / _` || '"'"'_ \ / __|'
    '| (__ | (_| || | | |\__ \'
    ' \___| \__,_||_| |_||___/'
  )
  for ((i = 0; i < 5; i++)); do
    echo "      ${coral}${bold}${two[i]}${off}${teal}${bold}${cans[i]}${off}"
  done
  echo
  echo "             ${dim}a tiny phone company, run by you${off}"
}

# A box around what matters most at the end. Lines are plain text, so the
# padding counts right; only the frame is coloured.
box() {
  local width=0 line rule=""
  for line in "$@"; do (( ${#line} > width )) && width=${#line}; done
  while (( ${#rule} < width + 4 )); do rule+="─"; done
  echo "  ${teal}╭${rule}╮${off}"
  for line in "$@"; do printf "  ${teal}│${off}  %-${width}s  ${teal}│${off}\n" "$line"; done
  echo "  ${teal}╰${rule}╯${off}"
}

# Run a slow step with a spinner, its output kept aside for if it fails.
spin_frames=(⠋ ⠙ ⠹ ⠸ ⠼ ⠴ ⠦ ⠧ ⠇ ⠏)
spinning() {
  local what=$1 pid=$2 i=0
  while kill -0 "$pid" 2>/dev/null; do
    printf '\r  %s %s…' "${teal}${spin_frames[i++ % 10]}${off}" "$what"
    sleep 0.1
  done
  printf '\r\033[K'
}

# Questions come from the terminal even if stdin is redirected. With no
# terminal, or --yes, every question takes its suggested answer.
CAN_ASK=false; CAN_PROMPT=false
if ! $ASSUME_YES && [[ -r /dev/tty ]] && { : < /dev/tty; } 2>/dev/null; then
  CAN_PROMPT=true
  $CHECK_ONLY || CAN_ASK=true
fi

# ask VAR "question" "suggested" [validator]
ask() {
  local var=$1 question=$2 suggested=$3 check=${4:-} answer
  if ! $CAN_ASK; then
    printf -v "$var" '%s' "$suggested"
    $CHECK_ONLY || ok "${question}: ${bold}${suggested}${off}"
    return
  fi
  while :; do
    read -r -p "  ${question} [${bold}${suggested}${off}]: " answer < /dev/tty || answer=""
    answer=${answer:-$suggested}
    if [[ -z "$check" ]] || "$check" "$answer"; then
      printf -v "$var" '%s' "$answer"
      return
    fi
  done
}

# confirm "question" [default y|n] — true for yes.
confirm() {
  local question=$1 default=${2:-y} answer hint
  if ! $CAN_PROMPT; then
    [[ "$default" == y ]]
    return
  fi
  [[ "$default" == y ]] && hint="Y/n" || hint="y/N"
  read -r -p "  ${question} [${hint}]: " answer < /dev/tty || answer=""
  answer=${answer:-$default}
  [[ "$answer" =~ ^[Yy] ]]
}


# Docker's own progress goes to a log, shown only if a step fails.
LOG=$(mktemp)
trap 'rm -f "$LOG"' EXIT
quietly() {
  local what=$1; shift
  local status=0
  if $TTY; then
    "$@" > "$LOG" 2>&1 &
    local pid=$!
    spinning "$what" "$pid"
    wait "$pid" || status=$?
  else
    echo "  ${what}…"
    "$@" > "$LOG" 2>&1 || status=$?
  fi
  if (( status != 0 )); then
    trap - ERR
    echo
    bad "${bold}${what} didn't work.${off}"
    echo "    ${dim}The last of what it said:${off}"
    tail -15 "$LOG" | sed 's/^/      /'
    explain_failure "$LOG"
    help_footer
    exit 1
  fi
}

# What a failed step's output usually means, in plain words, and what to do.
explain_failure() {
  local log=$1 say=() 
  if grep -qiE 'no space left on device' "$log"; then
    say=("This machine is out of disk space." "Free some up — 'docker system prune' removes Docker's old leftovers" "Check with: df -h")
  elif grep -qiE 'toomanyrequests|rate limit' "$log"; then
    say=("Docker Hub is limiting downloads from your internet connection for now." "Wait an hour or so" "Or sign in to a free Docker Hub account: docker login")
  elif grep -qiE 'no matching manifest|exec format error|platform .* does not match' "$log"; then
    say=("The images don't match this machine's processor." "twocans needs a 64-bit system — on a Raspberry Pi, the 64-bit Raspberry Pi OS")
  elif grep -qiE 'temporary failure in name resolution|could not resolve|no such host|dial tcp|i/o timeout|network is unreachable|tls handshake timeout|connection refused|connection reset' "$log"; then
    say=("It couldn't reach the internet." "Check this machine is online: ping -c1 github.com" "If you use a proxy or VPN, check it lets Docker through")
  elif grep -qiE 'permission denied.*docker\.sock|got permission denied' "$log"; then
    say=("You don't have permission to use Docker." "Add yourself: sudo usermod -aG docker $(id -un)" "Log out and back in")
  elif grep -qiE 'address already in use|port is already allocated' "$log"; then
    say=("A port twocans needs is in use by something else." "Run ./install.sh --check to see which")
  elif grep -qiE 'unable to locate package|no package .* available|target not found|not found in any repositories' "$log"; then
    say=("Your package manager doesn't have what was needed." "Update its list of packages and try again" "Or install Docker by hand: https://docs.docker.com/engine/install/")
  elif grep -qiE 'unsupported distribution|is not supported' "$log"; then
    say=("Docker's install script doesn't know this Linux." "Install Docker by hand: https://docs.docker.com/engine/install/" "Then run ./install.sh again")
  fi
  (( ${#say[@]} )) || return 0
  echo
  echo "    ${bold}What this usually means:${off} ${say[0]}"
  echo "    What to do:"
  local i
  for ((i = 1; i < ${#say[@]}; i++)); do echo "      ${bold}${i}.${off} ${say[i]}"; done
  echo "      ${bold}${#say[@]}.${off} Then run it again"
}

# Wait (with the spinner) until a check passes, or give up after $2 seconds.
wait_for() {
  local what=$1 seconds=$2; shift 2
  local until=$(( SECONDS + seconds )) i=0
  while (( SECONDS < until )); do
    "$@" >/dev/null 2>&1 && { $TTY && printf '\r\033[K'; return 0; }
    if $TTY; then
      printf '\r  %s %s…' "${teal}${spin_frames[i++ % 10]}${off}" "$what"
      sleep 0.2
    else
      sleep 2
    fi
  done
  $TTY && printf '\r\033[K'
  return 1
}

