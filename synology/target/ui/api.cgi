#!/bin/bash
#
# What twocans' window in DSM (panel.html) asks: how twocans is, its log, and
# requests to restart it, set it up again, or change its settings. DSM runs
# this as the package's own user, which can't use Docker: the setup container
# (setup/run.sh) writes how twocans is, and carries out what's asked, reading
# the request as data — so every value is checked here, and again there.
#
#   GET  api.cgi?action=status[&live=1]  how it is, as JSON — and the numbers
#   GET  api.cgi?action=log&part=P       a part's last lines (setup: the package log)
#   GET  api.cgi?action=download         the whole package log, to save
#   GET  api.cgi?action=report           the support report, once made, to save
#   GET  api.cgi?action=export           the last export, to save
#   GET  api.cgi?action=owner            a reset Owner password, shown once
#   POST api.cgi  action=restart | setup | settings | report | owner | check |
#                 hangup | retry | reregister | transcription | export      (to the setup container)
#                 prefs | viewers                                          (kept here)
#
# For someone signed in to DSM: an administrator, for everything; a DSM user
# an administrator named in the window's Settings, to look (status only).

# A test can stand in for these; a browser can't (only HTTP_* comes from it).
VAR=${TWOCANS_VAR:-/var/packages/twocans/var}
SHARE=${TWOCANS_SHARE:-/var/packages/twocans/shares/twocans}
AUTH=${TWOCANS_AUTH_CGI:-/usr/syno/synoman/webman/modules/authenticate.cgi}
ADMINS=${TWOCANS_ADMINS:-administrators}

reply() {   # status, type, body
  printf 'Status: %s\r\nContent-Type: %s\r\nCache-Control: no-store\r\n\r\n%s' "$1" "$2" "$3"
  exit 0
}
error() { reply "$1" application/json "{\"error\":\"$2\"}"; }

# Who's asking: DSM's own sign-in check names the user, from their session.
user=$("$AUTH" 2>/dev/null | head -1 | tr -cd 'A-Za-z0-9._@-')
[[ -n "$user" ]] || error "401 Unauthorized" "Sign in to DSM first."
if id -Gn "$user" 2>/dev/null | tr ' ' '\n' | grep -qx "$ADMINS"; then role=admin
elif grep -qxF "$user" "$VAR/viewers" 2>/dev/null; then role=viewer
else error "403 Forbidden" "Ask a DSM administrator to let you look at twocans — they can, in its window's Settings."
fi
# Looking, and nothing more, for anyone but an administrator.
admin_only() { [[ "$role" == admin ]] || error "403 Forbidden" "Only DSM administrators can do that."; }

prefget() { local v; v=$(sed -n "s/^$1='\([01]\)'$/\1/p" "$VAR/prefs" 2>/dev/null | tail -1); echo "${v:-$2}"; }
param() { sed -n "s/^\(.*&\)\{0,1\}$1=\([^&]*\).*$/\2/p" <<< "$2" | head -1; }
# A form value decoded: + is a space, %XX a byte.
decode() { local v=${1//+/ }; printf '%b' "${v//%/\\x}"; }

if [[ "${REQUEST_METHOD:-GET}" == GET ]]; then
  action=$(param action "${QUERY_STRING:-}")
  [[ "$action" == status ]] || admin_only
  case "$action" in
    status)
      # Someone's watching: the setup container keeps the numbers fresh.
      touch "$VAR/watching" 2>/dev/null
      [[ "$(param live "${QUERY_STRING:-}")" == 1 ]] && touch "$VAR/watching-live" 2>/dev/null
      state=$(tr -cd 'a-z' < "$VAR/state" 2>/dev/null)
      pending=false; [[ -f "$VAR/request" ]] && pending=true
      report=null; [[ -f "$VAR/report.txt" ]] && report=$(stat -c %Y "$VAR/report.txt")
      part() { local j; j=$(cat "$VAR/$1.json" 2>/dev/null); echo "${j:-null}"; }
      # The window's switches, and who else may look.
      prefs="{\"watchdog\":$(prefget WATCHDOG 1),\"notify\":$(prefget NOTIFY 1),\"notify_phones\":$(prefget NOTIFY_PHONES 1)}"
      viewers=$(grep -E '^[A-Za-z0-9._@-]{1,64}$' "$VAR/viewers" 2>/dev/null | sed 's/.*/"&"/' | paste -sd, -)
      extra=""
      [[ "$role" == admin ]] && extra=",\"report\":$report,\"export\":$(part export),\"certs\":$(part certs),\"prefs\":$prefs,\"viewers\":[${viewers}]"
      extra+=",\"memory\":$(part memory)"
      stats=stats; [[ "$role" == admin ]] || stats=stats-view   # without what was said
      reply "200 OK" application/json "{\"role\":\"$role\",\"user\":\"$user\",\"state\":\"${state:-unknown}\",\"pending\":$pending,\"now\":$(date +%s),\"status\":$(part status),\"stats\":$(part $stats),\"resources\":$(part resources),\"update\":$(part update),\"live\":$(part live)$extra}" ;;
    log)
      part=$(param part "${QUERY_STRING:-}")
      case "$part" in
        setup|"") reply "200 OK" "text/plain; charset=utf-8" "$(tail -n 400 "$VAR/package.log" 2>/dev/null)" ;;
        asterisk|mariadb|web|transcriber|whisper|pager)
          touch "$VAR/watching-logs" 2>/dev/null
          reply "200 OK" "text/plain; charset=utf-8" "$(cat "$VAR/logs/$part.log" 2>/dev/null || echo "Its log comes in a moment…")" ;;
        *) error "400 Bad Request" "Unknown part." ;;
      esac ;;
    report)
      [[ -f "$VAR/report.txt" ]] || error "404 Not Found" "No support report has been made yet."
      printf 'Status: 200 OK\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Disposition: attachment; filename="twocans-report-%s.txt"\r\n\r\n' "$(date -r "$VAR/report.txt" +%Y%m%d-%H%M)"
      cat "$VAR/report.txt"
      exit 0 ;;
    export)
      name=$(cat "$VAR/export.json" 2>/dev/null | sed -n 's/.*"name":"\(twocans-export-[0-9-]*\.zip\)".*/\1/p')
      [[ -n "$name" && -f "$SHARE/storage/exports/$name" ]] || error "404 Not Found" "No export has been made yet."
      printf 'Status: 200 OK\r\nContent-Type: application/zip\r\nContent-Length: %s\r\nContent-Disposition: attachment; filename="%s"\r\n\r\n' "$(stat -c %s "$SHARE/storage/exports/$name")" "$name"
      cat "$SHARE/storage/exports/$name"
      exit 0 ;;
    recording)
      # A call's recording, to play in the window: only one the app named, in
      # the recordings folder, by its own kind of name.
      name=$(param name "${QUERY_STRING:-}")
      [[ "$name" =~ ^[0-9a-z._-]+\.wav$ ]] && grep -qE "\"recording\": *\"${name//./\\.}\"" "$VAR/stats.json" 2>/dev/null \
        || error "404 Not Found" "No such recording."
      file="$SHARE/docker/asterisk/recordings/$name"
      [[ -r "$file" ]] || error "404 Not Found" "That recording isn't there any more."
      printf 'Status: 200 OK\r\nContent-Type: audio/wav\r\nContent-Length: %s\r\nCache-Control: no-store\r\n\r\n' "$(stat -c %s "$file")"
      cat "$file"
      exit 0 ;;
    owner)
      # Once: whoever reads it next finds nothing.
      [[ -f "$VAR/owner-reset.json" ]] || error "404 Not Found" "Nothing to show."
      answer=$(cat "$VAR/owner-reset.json"); rm -f "$VAR/owner-reset.json"
      reply "200 OK" application/json "$answer" ;;
    download)
      printf 'Status: 200 OK\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Disposition: attachment; filename="twocans-package.log"\r\n\r\n'
      cat "$VAR/package.log" 2>/dev/null
      exit 0 ;;
    *) error "400 Bad Request" "Unknown request." ;;
  esac
fi

[[ "${REQUEST_METHOD:-}" == POST ]] || error "405 Method Not Allowed" "Unknown request."
admin_only
(( ${CONTENT_LENGTH:-0} > 0 && ${CONTENT_LENGTH:-0} < 4096 )) || error "400 Bad Request" "Unknown request."
read -r -n "$CONTENT_LENGTH" body

action=$(param action "$body")

# Kept here, not asked of the setup container: the window's switches, and
# who else may look. Each is written whole.
case "$action" in
  prefs)
    out=""
    for k in watchdog notify notify_phones; do
      v=$(param "$k" "$body"); [[ "$v" =~ ^[01]$ ]] || error "400 Bad Request" "Unknown request."
      out+="$(tr a-z A-Z <<< "$k")='$v'"$'\n'
    done
    printf '%s' "$out" > "$VAR/prefs.tmp" && mv "$VAR/prefs.tmp" "$VAR/prefs" || error "500 Internal Server Error" "Couldn't save that."
    reply "200 OK" application/json '{"ok":true}' ;;
  viewers)
    list=$(decode "$(param users "$body")" | tr ', ' '\n\n' | sed '/^$/d' | sort -u)
    while IFS= read -r v; do
      [[ -z "$v" || "$v" =~ ^[A-Za-z0-9._@-]{1,64}$ ]] || error "400 Bad Request" "A DSM user name has only letters, digits and . _ @ -"
    done <<< "$list"
    printf '%s\n' "$list" | sed '/^$/d' > "$VAR/viewers.tmp" && mv "$VAR/viewers.tmp" "$VAR/viewers" || error "500 Internal Server Error" "Couldn't save that."
    reply "200 OK" application/json '{"ok":true}' ;;
esac

[[ -f "$VAR/request" ]] && error "409 Conflict" "twocans is still busy with the last request."

case "$action" in
  restart|setup|report|check|retry|reregister) lines="ACTION='$action'" ;;
  hangup)
    channel=$(decode "$(param channel "$body")")
    [[ "$channel" =~ ^(PJSIP|Local)/[A-Za-z0-9_.@-]+-[0-9a-f]{8}$ ]] || error "400 Bad Request" "Unknown call."
    lines="ACTION='hangup'
CHANNEL='$channel'" ;;
  transcription)
    value=$(param value "$body"); [[ "$value" =~ ^(on|off)$ ]] || error "400 Bad Request" "Unknown request."
    lines="ACTION='transcription'
VALUE='$value'" ;;
  ring|pause|resume)
    phone=$(param phone "$body"); for=$(param for "$body")
    [[ "$phone" =~ ^[0-9]{1,9}$ ]] || error "400 Bad Request" "Unknown phone."
    [[ "$action" != pause || "$for" =~ ^([0-9]{1,4}|morning)$ ]] || error "400 Bad Request" "Unknown length."
    [[ "$action" == ring && "$phone" == 0 ]] && error "400 Bad Request" "Ring one phone at a time."
    lines="ACTION='$action'
PHONE='$phone'
FOR='${for:-60}'" ;;
  https)
    cert=$(param cert "$body"); domain=$(decode "$(param domain "$body")" | tr 'A-Z' 'a-z'); address=$(param address "$body")
    [[ "$cert" =~ ^(default|none|[A-Za-z0-9]{1,32})$ ]] || error "400 Bad Request" "Unknown certificate."
    [[ "$cert" == none || "$domain" =~ ^([a-z0-9-]{1,63}\.)+[a-z]{2,63}$ ]] || error "400 Bad Request" "A name like phone.example.com — not a wildcard."
    [[ "$address" =~ ^[01]$ ]] || address=0
    lines="ACTION='https'
CERT='$cert'
DOMAIN='$domain'
ADDRESS='$address'" ;;
  export)
    audio=$(param audio "$body"); [[ "$audio" =~ ^[01]$ ]] || error "400 Bad Request" "Unknown request."
    lines="ACTION='export'
AUDIO='$audio'" ;;
  owner)
    email=$(decode "$(param email "$body")"); passkeys=$(param passkeys "$body"); signout=$(param signout "$body")
    [[ -z "$email" || "$email" =~ ^[A-Za-z0-9._+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] || error "400 Bad Request" "That doesn't look like an email address."
    [[ "$passkeys" =~ ^[01]$ && "$signout" =~ ^[01]$ ]] || error "400 Bad Request" "Unknown request."
    rm -f "$VAR/owner-reset.json"
    lines="ACTION='owner'
OWNER_EMAIL='$email'
OWNER_PASSKEYS='$passkeys'
OWNER_SIGNOUT='$signout'" ;;
  settings)
    address=$(decode "$(param address "$body")"); web=$(decode "$(param web_port "$body")")
    https=$(decode "$(param https_port "$body")"); tz=$(decode "$(param tz "$body")")
    country=$(decode "$(param country "$body")"); model=$(decode "$(param model "$body")")
    [[ "$address" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || error "400 Bad Request" "The address should look like 192.168.1.10."
    for p in "$web" "$https"; do
      [[ "$p" =~ ^[0-9]{2,5}$ ]] && (( p >= 80 && p <= 65535 )) || error "400 Bad Request" "A port is a number from 80 to 65535."
    done
    [[ "$web" != "$https" ]] || error "400 Bad Request" "The web app and HTTPS need different ports."
    [[ "$tz" =~ ^([A-Z][A-Za-z_]+(/[A-Za-z0-9_+-]+)+|UTC)$ ]] || error "400 Bad Request" "A time zone looks like Europe/London."
    [[ "$country" =~ ^[0-9]{1,4}$ ]] || error "400 Bad Request" "A country calling code is digits, like 44."
    [[ "$model" =~ ^(base|small)$ ]] || error "400 Bad Request" "Speech-to-text is base or small."
    lines="ACTION='settings'
TWOCANS_LAN_IP='$address'
TWOCANS_HTTP_PORT='$web'
TWOCANS_HTTPS_PORT='$https'
TWOCANS_TZ='$tz'
TWOCANS_COUNTRY='$country'
TWOCANS_WHISPER_MODEL='$model'" ;;
  *) error "400 Bad Request" "Unknown request." ;;
esac

# Whole, or not at all: the setup container only sees a finished file.
printf '%s\nBY=%s\n' "$lines" "'$user'" > "$VAR/request.tmp" && mv "$VAR/request.tmp" "$VAR/request" \
  || error "500 Internal Server Error" "Couldn't pass the request on."
reply "202 Accepted" application/json '{"ok":true}'
