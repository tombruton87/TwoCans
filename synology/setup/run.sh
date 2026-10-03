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
# Whether a marker file was touched in the last $2 seconds.
fresh() { [[ -f "$1" ]] && (( $(date +%s) - $(stat -c %Y "$1") < $2 )); }

# What compose.yaml takes when .env leaves a setting out — an older .env may.
env_or() { local v; v=$(env_get "$1"); echo "${v:-$2}"; }

# DSM's own record of twocans' ports — the firewall's list of applications —
# as .env has them now. DSM wrote it from the package before the wizard ran,
# so with the usual ports.
sync_dsm() {
  local http https sip trunk rtp_lo rtp_hi
  http=$(env_get HTTP_PORT); https=$(env_get HTTPS_PORT)
  sip=$(env_get SIP_PORT); trunk=$(env_get TRUNK_SIP_PORT)
  rtp_lo=$(env_get RTP_PORT_START); rtp_hi=$(env_get RTP_PORT_END)
  [[ -n "$http" ]] || return 0
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
}

# Settings from a file the package's own user wrote — the wizard's answers
# (scripts/postinst) or a request from twocans' window in DSM (ui/api.cgi).
# This runs as root, with Docker's socket, so the file is read as data and
# never run: only the names given, each with a value of its own shape, and
# anything else and the whole file is refused.
read_settings() {   # file, allowed names…
  local file=$1 line key value re="^([A-Z_]+)='([A-Za-z0-9._/+@-]*)'$"; shift
  while IFS= read -r line || [[ -n "$line" ]]; do
    [[ -z "$line" ]] && continue
    [[ "$line" =~ $re ]] || return 1
    key=${BASH_REMATCH[1]}; value=${BASH_REMATCH[2]}
    [[ " $* " == *" $key "* ]] || return 1
    [[ -z "$value" ]] && continue
    case "$key" in
      TWOCANS_LAN_IP) [[ "$value" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] ;;
      TWOCANS_TZ) [[ "$value" =~ ^([A-Z][A-Za-z_]+(/[A-Za-z0-9_+-]+)+|UTC)$ ]] ;;
      TWOCANS_COUNTRY) [[ "$value" =~ ^[0-9]{1,4}$ ]] ;;
      TWOCANS_WHISPER_MODEL) [[ "$value" =~ ^(base|small)$ ]] ;;
      ACTION) [[ "$value" =~ ^(restart|setup|settings|report|owner|check|hangup|retry|reregister|transcription|export|https|ring|pause|resume)$ ]] ;;
      PHONE) [[ "$value" =~ ^[0-9]{1,9}$ ]] ;;
      FOR) [[ "$value" =~ ^([0-9]{1,4}|morning)$ ]] ;;
      CERT) [[ "$value" =~ ^(default|none|[A-Za-z0-9]{1,32})$ ]] ;;
      DOMAIN) [[ "$value" =~ ^([A-Za-z0-9-]{1,63}\.)+[A-Za-z]{2,63}$ ]] ;;
      ADDRESS) [[ "$value" =~ ^[01]$ ]] ;;
      CHANNEL) [[ "$value" =~ ^(PJSIP|Local)/[A-Za-z0-9_.@-]+-[0-9a-f]{8}$ ]] ;;
      VALUE) [[ "$value" =~ ^(on|off)$ ]] ;;
      AUDIO) [[ "$value" =~ ^[01]$ ]] ;;
      OWNER_EMAIL) [[ "$value" =~ ^[A-Za-z0-9._+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] ;;
      OWNER_PASSKEYS|OWNER_SIGNOUT) [[ "$value" =~ ^[01]$ ]] ;;
      BY) [[ "$value" =~ ^[A-Za-z0-9._@-]{1,64}$ ]] ;;
      *) [[ "$value" =~ ^[0-9]{1,6}$ ]] ;;   # ports and ids
    esac || return 1
    printf -v "$key" '%s' "$value"
    export "${key?}"
  done < "$file"
}
ANSWER_KEYS="TWOCANS_LAN_IP TWOCANS_TZ TWOCANS_COUNTRY TWOCANS_WHISPER_MODEL TWOCANS_HTTP_PORT TWOCANS_HTTPS_PORT TWOCANS_SIP_PORT TWOCANS_TRUNK_SIP_PORT TWOCANS_RTP_START TWOCANS_HOST_UID TWOCANS_HOST_GID"
REQUEST_KEYS="ACTION BY TWOCANS_LAN_IP TWOCANS_TZ TWOCANS_COUNTRY TWOCANS_WHISPER_MODEL TWOCANS_HTTP_PORT TWOCANS_HTTPS_PORT OWNER_EMAIL OWNER_PASSKEYS OWNER_SIGNOUT CHANNEL VALUE AUDIO CERT DOMAIN ADDRESS PHONE FOR"

# The window's switches (api.cgi writes $VAR/prefs): read one, 0 or 1, or
# its default when it isn't set — never run, as with the requests.
pref() { local v; v=$(sed -n "s/^$1='\([01]\)'$/\1/p" "$VAR/prefs" 2>/dev/null | tail -1); echo "${v:-$2}"; }

# Speech-to-text on or off, as the window last said; on unless it said off.
transcription() { [[ "$(cat "$VAR/transcription" 2>/dev/null)" == off ]] && echo off || echo on; }
# Off means its two parts stay stopped, whatever else starts them.
apply_transcription() {
  [[ "$(transcription)" == off ]] || return 0
  docker compose stop whisper transcriber >> "$LOG" 2>&1
}

# What each part's called, to people.
label() {
  case "$1" in
    asterisk) echo "phone service" ;; mariadb) echo "database" ;; web) echo "web app" ;;
    transcriber) echo "transcription" ;; whisper) echo "speech-to-text" ;; pager) echo "announcements" ;;
    *) echo "$1" ;;
  esac
}

# A DSM notification, in DSM's notification centre like Synology's own — its
# words in ui/texts. DSM's own tool sends it, and that only runs as root on
# the Synology itself, so a short-lived container steps into the Synology's
# namespaces to run it: the package's Docker access allows that, and nothing
# else of twocans' runs that way. Each message at most once in $2 hours.
APP_ID="TwoCans.AppInstance"
notify() {   # key, hours between, values for its {0} {1}…
  local key=$1 hours=$2 mark arg args=(); shift 2
  [[ "$(pref NOTIFY 1)" == 1 ]] || return 0
  for arg in "$@"; do args+=("$(printf '%s' "$arg" | tr -d '\000-\037' | cut -c1-80)"); done
  mkdir -p "$VAR/notified"
  mark="$VAR/notified/$key-$(printf '%s|' "${args[@]}" | md5sum | cut -c1-12)"
  fresh "$mark" $(( hours * 3600 )) && return 0
  touch "$mark"
  if docker run --rm --privileged --pid=host --network=host --entrypoint nsenter twocans-setup:local \
       -t 1 -m -u -i -n -p -- /usr/syno/bin/synodsmnotify -c "$APP_ID" @administrators \
       "$APP_ID:notification:title" "$APP_ID:notification:$key" "${args[@]}" >> "$LOG" 2>&1; then
    say "told DSM: $key${args[*]:+ (${args[*]})}"
  else
    say "a DSM notification ($key) didn't go — the lines above say why"
  fi
}

# The watchdog: a part that's stopped, or stopped answering, for a minute and
# a half is started again, and DSM told. One that's been started three times
# in the last hour is left, and DSM told it needs a look instead — starting
# it again and again wouldn't help.
declare -A BAD_SINCE=() RESTARTS=()
watchdog() {
  [[ "$(pref WATCHDOG 1)" == 1 && -z "$BUSY" && -f .package-setup ]] || return 0
  local name state health now recent t
  now=$(date +%s)
  for name in asterisk mariadb web transcriber whisper pager; do
    [[ "$(transcription)" == off && ( "$name" == whisper || "$name" == transcriber ) ]] && continue
    read -r state health < <(docker inspect -f '{{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{else}}-{{end}}' "twocans-$name" 2>/dev/null || echo "missing -")
    if [[ "$state" == running && "$health" != unhealthy ]]; then BAD_SINCE[$name]=""; continue; fi
    [[ -n "${BAD_SINCE[$name]:-}" ]] || { BAD_SINCE[$name]=$now; continue; }
    (( now - BAD_SINCE[$name] >= 90 )) || continue
    recent=0
    for t in ${RESTARTS[$name]:-}; do (( now - t < 3600 )) && (( recent++ )); done
    if (( recent >= 3 )); then
      notify failing 6 "$(label "$name")" "$recent"
      continue
    fi
    say "watchdog: the $(label "$name") $([[ "$state" == running ]] && echo "stopped answering" || echo "stopped") — starting it again"
    if [[ "$state" == running ]]; then docker restart "twocans-$name" >> "$LOG" 2>&1
    else docker compose up -d --no-deps "$name" >> "$LOG" 2>&1; fi
    RESTARTS[$name]="${RESTARTS[$name]:-} $now"; BAD_SINCE[$name]=""
    notify restarted 1 "$(label "$name")"
  done
}

# What the app's numbers say that's worth DSM's notice: a phone offline for a
# day, the phone line no longer registered, a newer twocans. Only changes
# are told, each once.
declare -A OFFLINE_SINCE=()
LINE_DOWN=0; LINE_TOLD=""
watch_numbers() {
  local now name provider down
  [[ -f "$VAR/stats.json" ]] || return 0
  now=$(date +%s)
  if [[ "$(pref NOTIFY_PHONES 1)" == 1 ]]; then
    while IFS= read -r name; do
      [[ -n "$name" ]] || continue
      [[ -n "${OFFLINE_SINCE[$name]:-}" ]] || OFFLINE_SINCE[$name]=$now
      (( now - OFFLINE_SINCE[$name] >= 86400 )) && notify phone_offline 24 "$name"
    done < <(jq -r '.app.phones[]? | select(.registered and (.online | not)) | .name' "$VAR/stats.json" 2>/dev/null)
    for name in "${!OFFLINE_SINCE[@]}"; do
      jq -e --arg n "$name" '.app.phones[]? | select(.name == $n and .online)' "$VAR/stats.json" >/dev/null 2>&1 && unset 'OFFLINE_SINCE[$name]'
    done
  fi
  down=$(jq -r '[.registrations[]? | select(.status != "Registered")] | length' "$VAR/stats.json" 2>/dev/null)
  provider=$(jq -r '.app.line.provider // "your provider"' "$VAR/stats.json" 2>/dev/null)
  if (( ${down:-0} > 0 )); then
    (( LINE_DOWN++ ))
    (( LINE_DOWN == 2 )) && [[ "$LINE_TOLD" != down ]] && { notify line_down 6 "$provider"; LINE_TOLD=down; }
  else
    LINE_DOWN=0
    [[ "$LINE_TOLD" == down ]] && { notify line_up 0 "$provider"; LINE_TOLD=up; }
  fi
  local latest; latest=$(jq -r '.latest // empty' "$VAR/update.json" 2>/dev/null)
  if [[ -n "$latest" && "$latest" != "$(cat "$PKG/app/backend/VERSION" 2>/dev/null)" ]] \
     && [[ "$(printf '%s\n%s\n' "$latest" "$(cat "$PKG/app/backend/VERSION")" | sort -V | tail -1)" == "$latest" ]]; then
    notify update 720 "$latest"
  fi
}

# ./install.sh, as the package runs it: into the log and the container's own.
run_install() {
  TWOCANS_PACKAGE=1 bash ./install.sh --yes 2>&1 | tee -a "$LOG"
  return "${PIPESTATUS[0]}"
}

# How twocans is, for its window in DSM (ui/api.cgi reads it): each part,
# the settings that matter there, and what it's busy with.
BUSY=""; LAST=null
json() { local s=${1//\\/\\\\}; s=${s//\"/\\\"}; printf '"%s"' "$(tr -d '\000-\037' <<< "$s")"; }
write_status() {
  local parts="" name state health started
  for name in twocans-asterisk twocans-mariadb twocans-web twocans-transcriber twocans-whisper twocans-pager; do
    read -r state health started < <(docker inspect -f '{{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{else}}-{{end}} {{.State.StartedAt}}' "$name" 2>/dev/null || echo "missing - -")
    parts+="${parts:+,}{\"name\":$(json "$name"),\"state\":$(json "$state"),\"health\":$(json "$health"),\"started\":$(json "$started")}"
  done
  {
    printf '{"updated":%s,"version":%s,"build":%s,"busy":%s,"last":%s,"parts":[%s],' \
      "$(date +%s)" "$(json "$(cat "$PKG/app/backend/VERSION" 2>/dev/null)")" "$(json "$BUILD")" "$(json "$BUSY")" "$LAST" "$parts"
    printf '"settings":{"address":%s,"app_url":%s,"name":%s,"web_port":%s,"https_port":%s,"sip_port":%s,"trunk_port":%s,"rtp_start":%s,"rtp_end":%s,"tz":%s,"country":%s,"model":%s}}\n' \
      "$(json "$(env_get SIP_DOMAIN)")" "$(json "$(env_get APP_URL)")" "$(json "$(env_get MDNS_NAME)")" \
      "$(json "$(env_or HTTP_PORT 8083)")" "$(json "$(env_or HTTPS_PORT 443)")" "$(json "$(env_or SIP_PORT 5060)")" \
      "$(json "$(env_or TRUNK_SIP_PORT 5062)")" "$(json "$(env_or RTP_PORT_START 10000)")" "$(json "$(env_or RTP_PORT_END 10100)")" \
      "$(json "$(env_or TZ Europe/London)")" "$(json "$(env_or DEFAULT_COUNTRY_CODE 44)")" "$(json "$(env_or WHISPER_MODEL base)")"
  } > "$VAR/status.json.tmp" && mv "$VAR/status.json.tmp" "$VAR/status.json"
}

# A file for the window only: the package's user reads it (through
# api.cgi), nobody else — some of it is the household's own.
for_window() {   # file — moved into place whole
  chown "${HOST_UID:-0}" "$1.tmp" 2>/dev/null; chmod 600 "$1.tmp"; mv "$1.tmp" "$1"
}

# The household's numbers — phones, calls, voicemail, the phone line, the
# last backup — from setup/stats.php, run inside the web container, and how
# many calls are going on now, from the phone service.
write_stats() {
  local active app version
  active=$(docker exec twocans-asterisk asterisk -rx 'core show channels count' 2>/dev/null | sed -n 's/^\([0-9]*\) active call.*/\1/p' | head -1)
  app=$(docker exec -i twocans-web php < "$PKG/setup/stats.php" 2>/dev/null | tail -1)
  [[ "$app" == "{"*"}" ]] || app="{}"
  version=$(docker exec twocans-web cat /var/www/html/VERSION 2>/dev/null | tr -cd '0-9A-Za-z.-')
  # The phone line's registrations, as Asterisk has them: a line that
  # registers says Registered; one the provider reaches by address has none.
  local regs="" rname rstatus
  while read -r rname rstatus; do
    regs+="${regs:+,}{\"name\":$(json "$rname"),\"status\":$(json "$rstatus")}"
  done < <(docker exec twocans-asterisk asterisk -rx 'pjsip show registrations' 2>/dev/null \
             | awk '$1 ~ /\// && $1 !~ /^<Registration/ { for (i = 2; i <= NF; i++) if ($i ~ /^(Registered|Unregistered|Rejected|Failed|Stopped|Rejected)$/) { split($1, n, "/"); print n[1], $i; break } }')
  printf '{"updated":%s,"active_calls":%s,"app_version":%s,"transcription":%s,"registrations":[%s],"app":%s}\n' \
    "$(date +%s)" "${active:-null}" "$(json "$version")" "$(json "$(transcription)")" "$regs" "$app" > "$VAR/stats.json.tmp" && for_window "$VAR/stats.json"
  # What DSM users who may only look get: the same, without what was said —
  # no transcripts, no recordings.
  jq -c '(.app.recent // empty) |= map(del(.transcript, .recording))' "$VAR/stats.json" > "$VAR/stats-view.json.tmp" 2>/dev/null \
    && for_window "$VAR/stats-view.json"
}

# What twocans takes: each part's processor and memory, the Synology's
# memory, and room on the volume — with what recordings, voicemail, backups
# and photos take, counted every ten minutes (it takes a while).
SIZES="{}"; SIZES_AT=0
write_resources() {
  local rows="" name cpu mem total avail disk_total disk_free names
  # Only twocans' own: with no names, docker stats would show every container.
  names=$(docker ps --filter name='^twocans-' --format '{{.Names}}' 2>/dev/null)
  [[ -n "$names" ]] && while IFS=$'\t' read -r name cpu mem; do
    rows+="${rows:+,}{\"name\":$(json "$name"),\"cpu\":$(json "$cpu"),\"mem\":$(json "$mem")}"
  done < <(docker stats --no-stream --format '{{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}' $names 2>/dev/null)
  total=$(awk '/^MemTotal:/ {print $2}' /proc/meminfo); avail=$(awk '/^MemAvailable:/ {print $2}' /proc/meminfo)
  read -r disk_total disk_free < <(df -Pk "$APP" 2>/dev/null | awk 'NR == 2 {print $2, $4}')
  if (( $(date +%s) - SIZES_AT > 600 )); then
    local d k=() part
    for d in docker/asterisk/recordings docker/asterisk/voicemail storage/backups storage/photos .; do
      part=$(du -sk "$APP/$d" 2>/dev/null | cut -f1)
      k+=("$(json "$d"):${part:-0}")
    done
    SIZES="{$(IFS=,; echo "${k[*]}")}"; SIZES_AT=$(date +%s)
  fi
  printf '{"updated":%s,"parts":[%s],"memory_kb":{"total":%s,"available":%s},"disk_kb":{"total":%s,"free":%s},"sizes_kb":%s}\n' \
    "$(date +%s)" "$rows" "${total:-0}" "${avail:-0}" "${disk_total:-0}" "${disk_free:-0}" "$SIZES" > "$VAR/resources.json.tmp" \
    && for_window "$VAR/resources.json"
}

# Memory over the last day, for the window's chart: a sample every five
# minutes, the Synology's and twocans' own.
sample_memory() {
  local total avail ours
  total=$(awk '/^MemTotal:/ {print $2}' /proc/meminfo); avail=$(awk '/^MemAvailable:/ {print $2}' /proc/meminfo)
  ours=$(docker stats --no-stream --format '{{.MemUsage}}' $(docker ps --filter name='^twocans-' --format '{{.Names}}' 2>/dev/null) 2>/dev/null \
    | awk '{ v = $1; u = v; sub(/[0-9.]+/, "", u); sub(/[A-Za-z]+$/, "", v)
             m = (u == "GiB" ? 1048576 : u == "MiB" ? 1024 : u == "KiB" ? 1 : u == "GB" ? 1000000 : u == "MB" ? 1000 : 1); s += v * m }
           END { printf "%d", s }')
  { tail -n 287 "$VAR/memory.log" 2>/dev/null; echo "$(date +%s) $(( total - avail )) ${ours:-0}"; } > "$VAR/memory.log.tmp" && mv "$VAR/memory.log.tmp" "$VAR/memory.log"
  awk 'BEGIN { printf "{\"samples\":[" } { printf "%s[%s,%s,%s]", (NR > 1 ? "," : ""), $1, $2, $3 } END { printf "]}\n" }' "$VAR/memory.log" \
    > "$VAR/memory.json.tmp" && for_window "$VAR/memory.json"
}

# Each part's last lines, while the window's Log tab is open.
write_logs() {
  local name
  mkdir -p "$VAR/logs"
  for name in asterisk mariadb web transcriber whisper pager; do
    docker logs --tail 300 "twocans-$name" > "$VAR/logs/$name.log.tmp" 2>&1
    for_window "$VAR/logs/$name.log"
  done
}

# The newest twocans release on GitHub, and its Synology package, to compare
# with what's installed. Every six hours, or when asked.
UPDATE_AT=0
check_updates() {
  local release latest page spk
  UPDATE_AT=$(date +%s)
  if ! release=$(curl -fsS --max-time 15 https://api.github.com/repos/tombruton87/TwoCans/releases/latest 2>/dev/null); then
    printf '{"checked":%s,"error":"GitHub couldn\x27t be reached"}\n' "$UPDATE_AT" > "$VAR/update.json.tmp" && for_window "$VAR/update.json"
    return 1
  fi
  latest=$(grep -oE '"tag_name": *"v?[0-9][0-9A-Za-z.-]*"' <<< "$release" | head -1 | sed -E 's/.*"v?([^"]+)"$/\1/')
  page=$(grep -oE '"html_url": *"https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/releases/tag/[A-Za-z0-9_.-]+"' <<< "$release" | head -1 | sed -E 's/.*"(https[^"]+)"$/\1/')
  spk=$(grep -oE '"browser_download_url": *"https://github\.com/[A-Za-z0-9_./-]+\.spk"' <<< "$release" | head -1 | sed -E 's/.*"(https[^"]+)"$/\1/')
  printf '{"checked":%s,"latest":%s,"page":%s,"spk":%s}\n' "$UPDATE_AT" "$(json "$latest")" "$(json "$page")" "$(json "$spk")" \
    > "$VAR/update.json.tmp" && for_window "$VAR/update.json"
}

# A support report, as ./twocans report makes one — private details taken out
# by the app — for the window to hand over.
make_report() {
  local made
  TWOCANS_PACKAGE=1 ./twocans report 2>&1 | tee -a "$LOG" >/dev/null
  made=$(ls -t storage/reports/twocans-report-*.txt 2>/dev/null | head -1)
  [[ -n "$made" ]] || return 1
  cp "$made" "$VAR/report.txt.tmp" && for_window "$VAR/report.txt"
}

# A new password for the Owner, from setup/reset-owner.php run inside the
# web container. The password is made here, shown once in the window, and
# written nowhere else — not in the log.
reset_owner() {
  local password req answer
  password=$(tr -dc 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789' < /dev/urandom | head -c 16 | sed -E 's/(.{4})/\1-/g; s/-$//')
  req=$(printf '{"password":%s,"email":%s,"passkeys":%s,"signout":%s}' "$(json "$password")" "$(json "${OWNER_EMAIL:-}")" \
    "$([[ ${OWNER_PASSKEYS:-0} == 1 ]] && echo true || echo false)" "$([[ ${OWNER_SIGNOUT:-0} == 1 ]] && echo true || echo false)")
  answer=$( { printf '<?php $req = json_decode(base64_decode("%s"), true); ?>' "$(printf '%s' "$req" | base64 -w0)"; cat "$PKG/setup/reset-owner.php"; } \
    | docker exec -i twocans-web php 2>/dev/null | tail -1)
  if [[ "$answer" == '{"ok":true'* ]]; then
    printf '%s,"password":%s}\n' "${answer%\}}" "$(json "$password")" > "$VAR/owner-reset.json.tmp" && for_window "$VAR/owner-reset.json"
    say "the Owner account was reset"
  else
    [[ "$answer" == "{"*"}" ]] || answer="{\"error\":\"twocans didn't answer — is the web app running?\"}"
    printf '%s\n' "$answer" > "$VAR/owner-reset.json.tmp" && for_window "$VAR/owner-reset.json"
    return 1
  fi
}

# The calls going on now, while the window's Live tab is open.
write_live() {
  local live
  live=$(docker exec -i twocans-web php < "$PKG/setup/live.php" 2>/dev/null | tail -1)
  [[ "$live" == "{"*"}" ]] || live='{"calls":[]}'
  printf '%s\n' "$live" > "$VAR/live.json.tmp" && for_window "$VAR/live.json"
}

# Something small asked of the app (setup/act.php): end a call, or send
# failed transcriptions back. Prints its JSON answer.
act() {   # JSON request
  { printf '<?php $req = json_decode(base64_decode("%s"), true); ?>' "$(printf '%s' "$1" | base64 -w0)"; cat "$PKG/setup/act.php"; } \
    | docker exec -i twocans-web php 2>/dev/null | tail -1
}

# Everything the household has, in a zip — as ./twocans export makes it,
# in the shared folder's storage/exports — for the window to hand over.
make_export() {   # 1 with audio, 0 without
  local made
  TWOCANS_PACKAGE=1 ./twocans export $([[ "$1" == 0 ]] && echo --no-audio) 2>&1 | tee -a "$LOG" >/dev/null
  made=$(ls -t storage/exports/twocans-export-*.zip 2>/dev/null | head -1)
  [[ -n "$made" ]] || return 1
  chown "${HOST_UID:-0}" "$made" 2>/dev/null; chmod 600 "$made" 2>/dev/null
  printf '{"name":%s,"bytes":%s,"at":%s,"audio":%s}\n' "$(json "${made##*/}")" "$(stat -c %s "$made")" "$(date +%s)" \
    "$([[ "$1" == 0 ]] && echo false || echo true)" > "$VAR/export.json.tmp" && for_window "$VAR/export.json"
}

# ----------------------------------------------------- HTTPS, from DSM's certificates
# DSM 7 keeps third-party packages out of its own Certificate settings, so
# twocans uses DSM's certificates as everyone does: each lives in
# _archive/<id>/ (fullchain.pem, privkey.pem), INFO names them, DEFAULT says
# which is DSM's default. They're read here (mounted read-only at /dsm-certs)
# and the one chosen in the window is copied to twocans' own — and copied
# again whenever DSM renews it.
CERTS_DIR=/dsm-certs/_archive
TWOCANS_CERTS=docker/nginx/certs

# The certificates DSM has, for the window: names, issuer, expiry — never keys.
write_certs() {
  local rows="" dir id desc names issuer until default self
  default=$(tr -cd 'A-Za-z0-9' < "$CERTS_DIR/DEFAULT" 2>/dev/null)
  for dir in "$CERTS_DIR"/*/; do
    id=$(basename "$dir"); [[ "$id" =~ ^[A-Za-z0-9]{1,32}$ && -f "$dir/fullchain.pem" ]] || continue
    desc=$(jq -r --arg id "$id" '.[$id].desc // ""' "$CERTS_DIR/INFO" 2>/dev/null)
    names=$(openssl x509 -in "$dir/cert.pem" -noout -ext subjectAltName 2>/dev/null | grep -oE 'DNS:[^,[:space:]]+' | sed 's/^DNS://' | sort -u)
    [[ -n "$names" ]] || names=$(openssl x509 -in "$dir/cert.pem" -noout -subject -nameopt multiline 2>/dev/null | sed -n 's/^ *commonName *= *//p')
    issuer=$(openssl x509 -in "$dir/cert.pem" -noout -issuer -nameopt multiline 2>/dev/null | sed -n 's/^ *\(organizationName\|commonName\) *= *//p' | head -1)
    until=$(date -d "$(openssl x509 -in "$dir/cert.pem" -noout -enddate 2>/dev/null | cut -d= -f2)" +%s 2>/dev/null || echo 0)
    self=false; [[ "$(openssl x509 -in "$dir/cert.pem" -noout -issuer 2>/dev/null | cut -d= -f2-)" == "$(openssl x509 -in "$dir/cert.pem" -noout -subject 2>/dev/null | cut -d= -f2-)" ]] && self=true
    rows+="${rows:+,}{\"id\":$(json "$id"),\"desc\":$(json "$desc"),\"names\":[$(printf '%s\n' "$names" | sed '/^$/d' | while read -r n; do printf '%s,' "$(json "$n")"; done | sed 's/,$//')],\"issuer\":$(json "$issuer"),\"until\":${until:-0},\"self_signed\":$self,\"default\":$([[ "$id" == "$default" ]] && echo true || echo false)}"
  done
  local chosen; chosen=$(sed -n "s/^CERT='\([^']*\)'$/\1/p" "$VAR/https" 2>/dev/null)
  local domain; domain=$(sed -n "s/^DOMAIN='\([^']*\)'$/\1/p" "$VAR/https" 2>/dev/null)
  printf '{"updated":%s,"certs":[%s],"chosen":%s,"domain":%s,"error":%s}\n' "$(date +%s)" "$rows" "$(json "$chosen")" "$(json "$domain")" "$(json "${CERT_ERROR:-}")" \
    > "$VAR/certs.json.tmp" && for_window "$VAR/certs.json"
}

# The certificate chosen in the window, put in twocans' place — when it's
# new, or DSM has renewed it. Says what it did; quiet when nothing changed.
CERT_ERROR=""
apply_cert() {
  local chosen domain id src sum
  [[ -f "$VAR/https" ]] || return 0
  chosen=$(sed -n "s/^CERT='\([^']*\)'$/\1/p" "$VAR/https"); domain=$(sed -n "s/^DOMAIN='\([^']*\)'$/\1/p" "$VAR/https")
  [[ -n "$chosen" ]] || return 0
  id=$chosen; [[ "$id" == default ]] && id=$(tr -cd 'A-Za-z0-9' < "$CERTS_DIR/DEFAULT" 2>/dev/null)
  src="$CERTS_DIR/$id"
  if [[ -z "$id" || ! -f "$src/fullchain.pem" || ! -f "$src/privkey.pem" ]]; then
    CERT_ERROR="DSM no longer has that certificate — choose another."; return 1
  fi
  if [[ -n "$domain" ]] && ! openssl x509 -in "$src/cert.pem" -noout -checkhost "$domain" 2>/dev/null | grep -q "does match"; then
    CERT_ERROR="That certificate isn't for $domain."; return 1
  fi
  CERT_ERROR=""
  sum=$(cat "$src/fullchain.pem" "$src/privkey.pem" | md5sum | cut -c1-32)
  [[ "$sum" == "$(cat "$VAR/https.applied" 2>/dev/null)" && -f "$TWOCANS_CERTS/fullchain.pem" ]] && return 0
  mkdir -p "$TWOCANS_CERTS"
  install -m 644 "$src/fullchain.pem" "$TWOCANS_CERTS/fullchain.pem.new" && install -m 600 "$src/privkey.pem" "$TWOCANS_CERTS/privkey.pem.new" \
    && mv "$TWOCANS_CERTS/fullchain.pem.new" "$TWOCANS_CERTS/fullchain.pem" && mv "$TWOCANS_CERTS/privkey.pem.new" "$TWOCANS_CERTS/privkey.pem" \
    || { CERT_ERROR="Couldn't copy the certificate into twocans."; return 1; }
  echo "$sum" > "$VAR/https.applied"
  docker exec twocans-web nginx -s reload >> "$LOG" 2>&1
  say "HTTPS now uses DSM's certificate $id${domain:+ for $domain}, as DSM has it today"
}

# A chosen certificate close to running out — DSM renews Let's Encrypt ones
# itself, so this is for one it can't, or hasn't.
watch_cert() {
  local until
  [[ -f "$TWOCANS_CERTS/fullchain.pem" && -f "$VAR/https" ]] || return 0
  until=$(date -d "$(openssl x509 -in "$TWOCANS_CERTS/fullchain.pem" -noout -enddate 2>/dev/null | cut -d= -f2)" +%s 2>/dev/null || echo 0)
  (( until > 0 && until - $(date +%s) < 14 * 86400 )) && notify cert_expiring 72 "$(( (until - $(date +%s)) / 86400 ))"
}

# Choosing, or stopping: the window's HTTPS card.
set_https() {   # request file
  local cert domain address url old port
  cert=$(sed -n "s/^CERT='\([^']*\)'$/\1/p" "$1"); domain=$(sed -n "s/^DOMAIN='\([^']*\)'$/\1/p" "$1"); address=$(sed -n "s/^ADDRESS='\([01]\)'$/\1/p" "$1")
  if [[ "$cert" == none ]]; then
    # Back to twocans' own: the certificate it had before, or a fresh
    # self-signed one, which the web container makes as it starts.
    old=$(sed -n "s/^OLD_URL='\([^']*\)'$/\1/p" "$VAR/https" 2>/dev/null)
    rm -f "$VAR/https" "$VAR/https.applied" "$TWOCANS_CERTS/fullchain.pem" "$TWOCANS_CERTS/privkey.pem"
    if [[ -f "$TWOCANS_CERTS/before-dsm/fullchain.pem" ]]; then cp -p "$TWOCANS_CERTS/before-dsm/"*.pem "$TWOCANS_CERTS/"; fi
    [[ "$old" =~ ^https?://[A-Za-z0-9.:-]+/?$ ]] && sed -i "s#^APP_URL=.*#APP_URL=$old#" .env
    say "HTTPS goes back to twocans' own certificate"
    docker compose up -d >> "$LOG" 2>&1; docker restart twocans-web >> "$LOG" 2>&1
    apply_transcription; CERT_ERROR=""; return 0
  fi
  # What twocans had, kept once, to go back to.
  if [[ ! -d "$TWOCANS_CERTS/before-dsm" && -f "$TWOCANS_CERTS/fullchain.pem" ]]; then
    mkdir -p "$TWOCANS_CERTS/before-dsm" && cp -p "$TWOCANS_CERTS/fullchain.pem" "$TWOCANS_CERTS/privkey.pem" "$TWOCANS_CERTS/before-dsm/"
  fi
  old=$(sed -n "s/^OLD_URL='\([^']*\)'$/\1/p" "$VAR/https" 2>/dev/null); old=${old:-$(env_get APP_URL)}
  printf "CERT='%s'\nDOMAIN='%s'\nADDRESS='%s'\nOLD_URL='%s'\n" "$cert" "$domain" "${address:-0}" "$old" > "$VAR/https"
  rm -f "$VAR/https.applied"
  apply_cert || { say "✗ $CERT_ERROR"; return 1; }
  if [[ "$address" == 1 && -n "$domain" ]]; then
    port=$(env_or HTTPS_PORT 443)
    url="https://$domain$([[ "$port" == 443 ]] || echo ":$port")"
    if [[ "$(env_get APP_URL)" != "$url" ]]; then
      sed -i "s#^APP_URL=.*#APP_URL=$url#" .env
      say "twocans' address is now $url"
      docker compose up -d >> "$LOG" 2>&1; apply_transcription
    fi
  fi
}

# A request from twocans' window in DSM: restart it, set it up again, or
# change its settings and set it up with them. One at a time, in order.
handle_request() {
  local file="$VAR/request.taking" action by rc
  mv "$VAR/request" "$file" 2>/dev/null || return 0
  action=$(sed -n "s/^ACTION='\([a-z]*\)'$/\1/p" "$file" | head -1)
  by=$(sed -n "s/^BY='\([A-Za-z0-9._@-]*\)'$/\1/p" "$file" | head -1)
  if ! ( read_settings "$file" $REQUEST_KEYS ); then
    say "a request from DSM wasn't in order, so it was ignored"
    rm -f "$file"; return 0
  fi
  case "$action" in
    restart)
      BUSY="restarting"; write_status
      say "restarting twocans, as ${by:-someone} asked in DSM"
      docker compose restart 2>&1 | tee -a "$LOG"; rc=${PIPESTATUS[0]}; apply_transcription ;;
    setup|settings)
      BUSY=$([[ "$action" == settings ]] && echo "applying new settings" || echo "setting up again"); write_status
      say "${BUSY}, as ${by:-someone} asked in DSM"
      ( read_settings "$file" $REQUEST_KEYS && run_install ); rc=$?
      (( rc == 0 )) && { echo "$BUILD" > .package-setup; sync_dsm; apply_transcription; } || container_report ;;
    report)
      BUSY="making a support report"; write_status
      say "making a support report, as ${by:-someone} asked in DSM"
      make_report; rc=$? ;;
    owner)
      BUSY="resetting the Owner account"; write_status
      say "resetting the Owner account, as ${by:-someone} asked in DSM"
      ( read_settings "$file" $REQUEST_KEYS && reset_owner ); rc=$? ;;
    check)
      check_updates; rc=$? ;;
    hangup)
      local channel answer
      channel=$(sed -n "s/^CHANNEL='\([^']*\)'$/\1/p" "$file" | head -1)
      say "ending a call, as ${by:-someone} asked in DSM"
      answer=$(act "$(printf '{"do":"hangup","channel":%s}' "$(json "$channel")")")
      [[ "$answer" == '{"ok":true'* ]]; rc=$?
      write_live ;;
    retry)
      local answer
      answer=$(act '{"do":"retry"}')
      say "sent failed transcriptions back to speech-to-text: $answer"
      [[ "$answer" == '{"ok":true'* ]]; rc=$? ;;
    reregister)
      say "registering the phone line again, as ${by:-someone} asked in DSM"
      docker exec twocans-asterisk asterisk -rx 'pjsip send register *all' 2>&1 | tee -a "$LOG" >/dev/null; rc=${PIPESTATUS[0]}
      sleep 4; write_stats ;;
    transcription)
      local value
      value=$(sed -n "s/^VALUE='\(on\|off\)'$/\1/p" "$file" | head -1)
      BUSY="turning speech-to-text $value"; write_status
      say "turning speech-to-text $value, as ${by:-someone} asked in DSM"
      echo "$value" > "$VAR/transcription"
      if [[ "$value" == off ]]; then apply_transcription; rc=$?
      else docker compose up -d whisper transcriber 2>&1 | tee -a "$LOG" >/dev/null; rc=${PIPESTATUS[0]}; fi
      write_stats ;;
    ring|pause|resume)
      local phone for answer
      phone=$(sed -n "s/^PHONE='\([0-9]*\)'$/\1/p" "$file" | head -1); for=$(sed -n "s/^FOR='\([0-9a-z]*\)'$/\1/p" "$file" | head -1)
      answer=$(act "$(printf '{"do":"%s","phone":%s,"for":%s}' "$action" "${phone:-0}" "$(json "${for:-60}")")")
      say "$action $([[ "${phone:-0}" == 0 ]] && echo "every phone" || echo "phone $phone")$([[ "$action" == pause ]] && echo " (${for:-60})"), as ${by:-someone} asked in DSM: $answer"
      [[ "$answer" == '{"ok":true'* ]]; rc=$?
      write_stats ;;
    https)
      BUSY="setting up HTTPS"; write_status
      say "setting up HTTPS, as ${by:-someone} asked in DSM"
      set_https "$file"; rc=$?
      write_certs ;;
    export)
      local audio
      audio=$(sed -n "s/^AUDIO='\([01]\)'$/\1/p" "$file" | head -1)
      BUSY="making an export"; write_status
      say "making an export$([[ "$audio" == 0 ]] && echo " (without audio)"), as ${by:-someone} asked in DSM"
      make_export "${audio:-1}"; rc=$? ;;
    *) rc=1 ;;
  esac
  rm -f "$file"
  LAST="{\"action\":$(json "$action"),\"ok\":$( (( rc == 0 )) && echo true || echo false),\"at\":$(date +%s),\"by\":$(json "$by")}"
  (( rc == 0 )) && say "✓ done" || say "✗ that didn't finish (above)"
  BUSY=""; write_status; publish_log
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
  notify setup_failed 6
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
  read_settings "$VAR/answers.env" $ANSWER_KEYS || failed "The install wizard's answers weren't in order — reinstalling the package asks them again."
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
  apply_transcription
else
  say "setting twocans up with ./install.sh — the first time, about 3 GB of images download, so give it a while"
  publish_log
  # Into the log, and the container's own (Container Manager → Container → Log).
  if ! run_install; then
    container_report
    failed "Setting twocans up didn't finish (above)."
  fi
  echo "$BUILD" > .package-setup
  [[ -f "$VAR/answers.env" ]] && mv "$VAR/answers.env" "$VAR/answers.applied"
  apply_transcription
fi

sync_dsm
set_state running
say "✓ twocans is running: $(env_get APP_URL)"
publish_log

# Until the package stops: requests from DSM taken as they come, and how
# twocans is written down every ten seconds. Waiting on a child, so the stop
# is heard at once.
# While the window is open (api.cgi touches $VAR/watching as it asks),
# the numbers are kept fresh; otherwise they're counted once a minute.
write_resources; write_stats
apply_cert; write_certs
UPDATE_AT=$(( $(date +%s) - 21600 + 60 ))   # the first look a minute from now
tick=0
while true; do
  [[ -f "$VAR/request" ]] && handle_request
  watched=false; fresh "$VAR/watching" 20 && watched=true
  (( tick % 5 == 0 )) && write_status
  if $watched; then
    (( tick % 5 == 0 )) && { write_stats; write_resources; }
    fresh "$VAR/watching-logs" 15 && (( tick % 3 == 0 )) && write_logs
    fresh "$VAR/watching-live" 15 && (( tick % 2 == 0 )) && write_live
  else
    (( tick % 30 == 0 )) && write_stats
  fi
  (( tick % 15 == 0 )) && { watchdog; watch_numbers; }
  (( tick % 150 == 0 )) && sample_memory
  # DSM's certificates: listed for the window, and the chosen one followed
  # through DSM's renewals — every few minutes, and at once when watched.
  if (( tick % 150 == 0 )) || { $watched && (( tick % 15 == 0 )); }; then apply_cert; write_certs; watch_cert; fi
  (( $(date +%s) - UPDATE_AT > 21600 )) && check_updates
  (( tick++ ))
  sleep 2 &
  wait $!
done
