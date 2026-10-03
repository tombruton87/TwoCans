#!/bin/bash
#
# twocans' install wizard, in Package Center. Made fresh each time, so it can
# suggest this Synology's own address and time zone. The answers reach
# scripts/postinst as wizard_* variables, and ./install.sh from there.

# This Synology's address on the home network.
lan_ip=$(ip route get 1.1.1.1 2>/dev/null | sed -n 's/.* src \([0-9.]*\).*/\1/p' | head -1)

# Its time zone. DSM keeps its own word for it ("London"), which is found
# among the zone names.
tz=""
syno=$(sed -n 's/^timezone="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' /etc/synoinfo.conf 2>/dev/null | head -1)
if [ -n "$syno" ] && [ -d /usr/share/zoneinfo ]; then
  tz=$(cd /usr/share/zoneinfo && ls -d */"$syno" 2>/dev/null | grep -vE '^(posix|right)/' | head -1)
fi
tz=${tz:-Europe/London}

# Its calling code, from the time zone — the same guesses as ./install.sh.
case "$tz" in
  Europe/London|Europe/Belfast) country=44 ;; Europe/Dublin) country=353 ;;
  America/*) country=1 ;; Australia/*) country=61 ;; Pacific/Auckland) country=64 ;;
  Europe/Paris) country=33 ;; Europe/Berlin) country=49 ;; Europe/Madrid) country=34 ;;
  Europe/Rome) country=39 ;; Europe/Amsterdam) country=31 ;; Europe/Brussels) country=32 ;;
  Europe/Stockholm) country=46 ;; Europe/Oslo) country=47 ;; Europe/Copenhagen) country=45 ;;
  *) country=44 ;;
esac

# Ports something here already listens on, TCP and UDP together: the
# Synology's own (5000, 5001, 80, 443…) and any other package's.
if command -v netstat >/dev/null 2>&1; then
  used=$(netstat -lntu 2>/dev/null | awk 'NR > 2 {print $4}')
else
  used=$(ss -Hlntu 2>/dev/null | awk '{print $5}')
fi
used=$(printf '%s\n' "$used" | sed 's/.*://' | grep -E '^[0-9]+$' | sort -un)
in_use() { printf '%s\n' "$used" | grep -qx "$1"; }
# The first of the usual ports that's free; the first, if none is.
first_free() { local p; for p in "$@"; do in_use "$p" || { echo "$p"; return; }; done; echo "$1"; }
http_port=$(first_free 8083 8084 8090 8180 8280)
https_port=$(first_free 8443 9443 10443 11443)
sip_port=$(first_free 5060 5070 5080 5090)
trunk_port=$(first_free 5062 5064 5072 5082)

# A port typed in must be a number, not one in use, and not inside the call
# audio range — a JavaScript pattern, which the wizard checks as it's typed.
taken=$(printf '%s\n' "$used" | paste -sd'|' -)
port_expr="/^(?!(?:${taken:+$taken|}100[0-9][0-9]|10100)\$)[1-9][0-9]{1,4}\$/"
in_use_text=$(printf '%s\n' "$used" | head -25 | paste -sd',' - | sed 's/,/, /g')
[ -n "$in_use_text" ] || in_use_text="none found"

# Call audio takes a run of 101 ports: the usual 10000–10100 if they're all
# free, else the first free run of the ones ./install.sh would offer.
rtp_busy=$(printf '%s\n' "$used" | awk '$1 >= 10000 && $1 <= 10100' | head -1)
rtp_start=10000
if [ -n "$rtp_busy" ]; then
  for lo in 20000 30000 40000 11000 12000 13000 14000 15000 16000; do
    [ -z "$(printf '%s\n' "$used" | awk -v lo="$lo" '$1 >= lo && $1 <= lo + 100' | head -1)" ] && { rtp_start=$lo; break; }
  done
  rtp_text="Call audio usually uses UDP 10000–10100, but port <b>$rtp_busy</b> is already in use here, so it's suggested at <b>$rtp_start–$((rtp_start + 100))</b> instead."
else
  rtp_text="Call audio uses UDP <b>10000–10100</b>, which are free here."
fi

# Only their own characters, as they go into JSON.
lan_ip=$(printf '%s' "$lan_ip" | tr -cd '0-9.')
tz=$(printf '%s' "$tz" | tr -cd 'A-Za-z0-9_/+-')

cat > "$SYNOPKG_TEMP_LOGFILE" <<JSON
[{
    "step_title": "Welcome to twocans",
    "items": [{
        "desc": "twocans turns this Synology into a phone exchange for your family: real phones around the house, a phone line of its own, and you deciding who the children can call.<br><br>It runs in Container Manager. Setting it up downloads about 3 GB, so the first start takes a while — you can follow it in <b>Package Center → twocans → View log</b>.<br><br>Recordings, voicemails, photos and backups go in a new shared folder, <b>twocans</b>. Its settings and data stay there if the package is ever removed. To open it in File Station, give yourself access: <b>Control Panel → Shared Folder → twocans → Edit → Permissions</b>."
    }]
}, {
    "step_title": "Your home network",
    "items": [{
        "type": "textfield",
        "desc": "The address phones reach this Synology on. It needs to stay the same: if your router hands it out, reserve it there (a DHCP reservation).",
        "subitems": [{
            "key": "wizard_lan_ip",
            "desc": "This Synology's address",
            "defaultValue": "$lan_ip",
            "emptyText": "192.168.1.10",
            "validator": {
                "allowBlank": false,
                "regex": {
                    "expr": "/^[0-9]{1,3}([.][0-9]{1,3}){3}$/",
                    "errorText": "An address like 192.168.1.10"
                }
            }
        }]
    }]
}, {
    "step_title": "Ports",
    "items": [{
        "desc": "Already in use on this Synology: $in_use_text. The suggestions below are free; change one only if you need it elsewhere."
    }, {
        "type": "textfield",
        "desc": "The web app, from your home network. (The Synology's own pages keep 5000 and 5001, and 80 and 443.)",
        "subitems": [{
            "key": "wizard_http_port",
            "desc": "Web app port",
            "defaultValue": "$http_port",
            "validator": { "allowBlank": false, "regex": { "expr": "$port_expr", "errorText": "A port that's free here, not 10000–10100" } }
        }, {
            "key": "wizard_https_port",
            "desc": "HTTPS port",
            "defaultValue": "$https_port",
            "validator": { "allowBlank": false, "regex": { "expr": "$port_expr", "errorText": "A port that's free here, not 10000–10100" } }
        }]
    }, {
        "type": "textfield",
        "desc": "Phones register on the first; your phone line provider reaches the second, through your router. Every phone and provider expects 5060 unless told otherwise, so keep these if you can.",
        "subitems": [{
            "key": "wizard_sip_port",
            "desc": "Phones (SIP)",
            "defaultValue": "$sip_port",
            "validator": { "allowBlank": false, "regex": { "expr": "$port_expr", "errorText": "A port that's free here, not 10000–10100" } }
        }, {
            "key": "wizard_trunk_sip_port",
            "desc": "Phone line (SIP)",
            "defaultValue": "$trunk_port",
            "validator": { "allowBlank": false, "regex": { "expr": "$port_expr", "errorText": "A port that's free here, not 10000–10100" } }
        }]
    }, {
        "type": "textfield",
        "desc": "$rtp_text It takes 101 ports from the first; your router forwards them for calls from your phone line.",
        "subitems": [{
            "key": "wizard_rtp_start",
            "desc": "Call audio's first port",
            "defaultValue": "$rtp_start",
            "validator": { "allowBlank": false, "regex": { "expr": "/^[1-5][0-9]{4}$/", "errorText": "A port from 10000 to 59999" } }
        }]
    }]
}, {
    "step_title": "Where you are",
    "items": [{
        "type": "textfield",
        "desc": "Bedtime and call hours follow this time zone.",
        "subitems": [{
            "key": "wizard_tz",
            "desc": "Time zone",
            "defaultValue": "$tz",
            "validator": {
                "allowBlank": false,
                "regex": {
                    "expr": "/^([A-Z][A-Za-z_]+([/][A-Za-z0-9_+-]+)+|UTC)$/",
                    "errorText": "A time zone like Europe/London"
                }
            }
        }]
    }, {
        "type": "textfield",
        "desc": "Phone numbers typed without a country code are taken to be from here.",
        "subitems": [{
            "key": "wizard_country",
            "desc": "Country calling code",
            "defaultValue": "$country",
            "validator": {
                "allowBlank": false,
                "regex": {
                    "expr": "/^[0-9]{1,4}$/",
                    "errorText": "Digits only, like 44 or 1"
                }
            }
        }]
    }, {
        "type": "combobox",
        "desc": "Speech-to-text writes down voicemails and calls, here on the Synology — audio never leaves it. <b>base</b> is quick; <b>small</b> copes better with noisy lines, at about three times the work.",
        "subitems": [{
            "key": "wizard_whisper_model",
            "desc": "Speech-to-text",
            "editable": false,
            "defaultValue": "base",
            "store": ["base", "small"],
            "validator": {
                "allowBlank": false
            }
        }]
    }]
}, {
    "step_title": "After it's installed",
    "items": [{
        "desc": "Once it's running, open <b>twocans</b> from DSM's main menu and create your account.<br><br>If this Synology's firewall is on, let twocans in: <b>Control Panel → Security → Firewall → Edit Rules</b>, and add the twocans entries under <i>Select from a list of built-in applications</i>. Your phones only need your home network; your phone line's ports and HTTPS need anywhere."
    }]
}]
JSON
exit 0
