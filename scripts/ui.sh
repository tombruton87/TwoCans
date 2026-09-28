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
die()     { bad "$*" >&2; exit 1; }

# ── Section ─────────────────────────────────── in teal, ruled to one width.
section() {
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
    bad "$what failed:"
    tail -20 "$LOG" | sed 's/^/    /'
    exit 1
  fi
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

