# TwoCans

A parents-only web admin for a self-hosted kids' phone line. Kids use an ordinary
handset plugged into a Grandstream ATA (HT801/HT802); parents use this app to
control **who** can call, **when**, and to review **what happened** — every call
recorded and transcribed on your own server.

The visual metaphor is two cans and a string: each device is a tin can, the string
is the connection (taut = online, cut = offline).

## License

TwoCans is licensed under the **Business Source License 1.1** (see `LICENSE`). In
short:

- **Free** to self-host for your own household, family, or any non-commercial
  use, including production use on your own box.
- **Not free** to run as a *Commercial Service* — a hosted, managed, shared or
  multi-tenant deployment, or offering telephony / phone-app / APN connectivity
  for a fee. A Commercial Service needs a separate commercial license from
  Hamlet Digital.
- It **converts to Apache-2.0** on its Change Date. Note the canonical BSL 1.1
  boilerplate switches at the **earlier** of the stated Change Date or **4 years
  after first release**, so the protected window is roughly **4 years**.

The "TwoCans" name and the two-cans mark are trademarks of Hamlet Digital and are
not granted by the license (see `NOTICE`).

## Quick start

You need Docker Engine + Compose and a machine your phones can reach. Then:

```bash
./install.sh          # checks prerequisites, writes .env, brings the stack up
docker compose up -d  # (or `make up`) once you already have a .env
```

Open the URL it prints and complete **first-run setup** to create the household
Owner. Then add a phone via its provisioning QR code and make a test call. See
[Running it](#running-it) for ports, `.env` values, and moving to another
machine.

## Status

**The phone line works.** Guardians, phones, contacts, call log, recordings,
transcription, voicemail and live listen-in are all real, in MariaDB and
Asterisk. Phones call each other, calls are recorded and transcribed, unanswered
calls go to voicemail and can be retrieved from a handset.

Not done yet: an **outside line** (a SIP trunk is configurable but untested
against a real provider), **ATA auto-provisioning** for the Grandstream
handsets, the **ask-to-call queue** on the dashboard, ring-both failover,
guardian invitation emails, and TLS.

### Accounts

On first visit, with no Owner in the database, the app shows **first-run setup**
instead of a login screen — that creates the household Owner. After that:

```bash
# apply schema changes (safe to re-run)
docker exec twocans-php php /var/www/html/bin/migrate.php

# set or reset a password (recovery path, and how invited guardians get one)
docker exec -it twocans-php php /var/www/html/bin/set-password.php you@home.co
docker exec twocans-php php /var/www/html/bin/set-password.php --list
```

The Twilio auth token is encrypted at rest with `APP_KEY` (a 64-hex-char
secret). Set it in the environment before connecting a phone line — generate
one with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`.

### What "real" means here

- **argon2id** at OWASP's recommended parameters (19 MiB, t=2, p=1). PHP's
  default of 64 MiB / t=4 was rejected deliberately: at ~284ms and 64 MiB per
  attempt it is a memory-pressure vector on a box also carrying RTP media.
- **Lockout** after 5 failed attempts per email (20 per IP) in a 15-minute
  window. A successful sign-in clears that account's failures.
- **No user enumeration** — unknown email, wrong password, and an invited
  guardian with no password set all return the same message, and an unknown
  account is still verified against a dummy hash so the timing matches.
- **Session regenerated** on every privilege change, defeating fixation.
- **Roles enforced server-side** in `src/actions.php`, on a deny-by-default map:
  an action with no entry is refused, so a new action cannot ship unguarded.
  The views hide controls a role can't use, but that is only cosmetic.

| | Owner | Admin | Viewer |
| --- | :-: | :-: | :-: |
| Phones, contacts, rules | ✅ | ✅ | — |
| Voicemail delete, live listen-in | ✅ | ✅ | — |
| Guardians & billing | ✅ | — | — |
| Read call log & voicemail, download transcripts | ✅ | ✅ | ✅ |

### Managing people

Everything is on **Guardians** (`/?screen=guardians`) — the CLI is only a
fallback now:

- **Add a grown-up** — name, email, role, and an optional password. Set one and
  they can sign in immediately; leave it blank and they appear as a pending
  invite tagged *No password*, which cannot be used to sign in.
- **⚿ next to each person** — set or reset their password. You can always change
  your own (which requires your current password, so a hijacked session can't
  lock you out); only an Owner can set someone else's, which is the recovery
  path when they're locked out. Either way it clears that account's lockout.

`bin/set-password.php` still exists for when nobody can get in at all.

Not yet done: invitation emails carrying a single-use token, and self-service
"forgot my password" reset. Setting another guardian's password also does not
invalidate their existing sessions — PHP's file-based sessions have no index to
revoke by. Worth a session table if this is ever exposed beyond a LAN.

Every screen from the design is implemented as server-rendered PHP. The parts
that are still stand-ins — the ask-to-call queue, top-up billing, the blocked
message preview — are marked in the source:

```bash
grep -rn "TODO(wire)" backend/
```

## Running it

Clone it anywhere and run the installer:

```bash
./install.sh
```

It checks Docker is present and working, finds this machine's LAN address,
checks the ports are free, writes `.env` with freshly generated secrets, brings
the stack up, applies migrations and generates Asterisk's config. `./install.sh
--check` runs the checks and changes nothing. Re-running it keeps an existing
`.env`.

There's also a `Makefile` with quick controls once you're set up — `make up`,
`make migrate`, `make status`, `make backup` and more (`make help` lists them).

Docker itself is **not** installed for you: doing that from a script is
invasive, differs per distribution and goes wrong on a machine that already has
it. The installer says what to install and stops.

Everything specific to a machine — addresses, ports, passwords, timezone — is in
`.env` and nowhere else. `.env.example` documents every value. The two that
matter most:

| | |
| --- | --- |
| `SIP_DOMAIN` | the address **phones register to**. Must be a LAN address they can reach; never `localhost`, never a public hostname — SIP and RTP do not follow the web address. |
| `APP_URL` | what a **browser** uses, and what the provisioning QR code points at. May be a domain once TLS is set up. |

Only two things are published to the host: the web UI, and Asterisk's SIP and
RTP ports. The database, ARI and AMI are reachable only on the compose network.

| Service | URL | Notes |
| --- | --- | --- |
| Web UI | `APP_URL` (default http://localhost:8083) | first visit creates the household Owner |
| SIP | `SIP_DOMAIN:5060` UDP + TCP | not movable — every SIP client assumes 5060 |
| RTP | `SIP_DOMAIN:10000-10100` UDP | audio; without it calls connect but are silent |
| Adminer | http://localhost:8089 | opt-in: `docker compose --profile tools up -d` |

Adminer is opt-in because it is a second, unauthenticated-by-default way into
every call record in the house. Start it when you need it; leave it off.

**Dockge** is optional — it only reads compose files from a directory. On this
host `/docker/dockge/stacks/twocans` is a symlink to this repo, so the stack
still appears there and the compose file has one home.

To iterate on the PHP without the full stack:

```bash
docker run --rm -p 8123:8080 -v "$PWD/backend:/app" -w /app php:8.3-cli-alpine php -S 0.0.0.0:8080 -t /app
```

### Moving it to another machine

Copy the repo, copy `.env`, and change `SIP_DOMAIN` and `APP_URL` to the new
address. Then:

```bash
docker compose up -d
docker compose exec php php /var/www/html/bin/apply-config.php
docker compose restart asterisk
```

The restart is not optional: `apply-config.php` rewrites the SIP transports,
which carry `SIP_DOMAIN`, and **transports are not reloadable** — a reload
leaves the old address in place and audio goes to the wrong host.

## Layout

```
backend/                     nginx docroot (bind-mounted, edit live)
  index.php                  front controller — routes ?screen=… and dispatches POSTs
  src/
    bootstrap.php            session, timezone, requires
    helpers.php              e(), url(), redirect(), CSRF, flash, view()
    Store.php                all reads/writes — swap this for MariaDB + ARI
    Presenter.php            record → labels, colours, CSS modifiers
    actions.php              POST handlers (one case per action)
    downloads.php            transcript .txt and call-log .csv exports
  views/
    login.php  layout.php
    partials/                head, logo, sidebar, header, bottom nav, equalizer
    screens/                 dashboard, phones, phone_detail, contacts,
                             calllog, voicemail, guardians, trunk
    modals/                  device_wizard, trunk_wizard, contact_editor, listen
  assets/css/twocans.css     design tokens + every component
  assets/js/twocans.js       progressive enhancement only

  migrations/                schema, applied in filename order by bin/migrate.php
  bin/                       migrate, set-password, apply-config, check-asterisk,
                             transcribe (the worker), sip-register-test

docker/
  nginx/default.conf         only index.php executes; src/ and views/ are denied
  php/                       Dockerfile, php.ini, www.conf (FPM pool)
  whisper/                   faster-whisper image, ~720MB, no GPU needed
  asterisk/etc/              hand-written config; generated/ is written at runtime

compose.yaml                 all paths relative — clone this repo anywhere
install.sh                   checks, .env, first run
.env                         every machine-specific value (not in git)
```

## How it's put together

**Server-rendered, no build step.** Navigation is plain links (`?screen=phones`),
actions are forms that POST and redirect (so a refresh never replays them), and
every form carries a CSRF token. JavaScript is *enhancement only* — it replays the
logo animation, auto-saves inline edits on blur, ticks the live-call timer, and
advances the wizard "waiting" steps. Everything still works with JS off.

**Styling** uses CSS custom properties for the design tokens (colours, fonts,
shadows, radii) and one class per component, rather than porting the prototype's
inline styles. Exact token values are in the `:root` block of `twocans.css`.

**Responsive** behaviour is driven by media queries, not the prototype's
Desktop/Tablet/Phone switcher: below 880px the sidebar is replaced by the bottom
tab bar. The handoff notes that switcher existed only to demo both layouts in one
file, so it is not carried over.

## Adding a phone

Adding a phone is real. **Phones → Add a phone** creates a device row, generates
a PJSIP endpoint/auth/AOR into `docker/asterisk/etc/generated/`, reloads
Asterisk, and shows the credentials laid out to match Linphone's setup screen
field-for-field.

| Linphone field | What twocans shows |
| --- | --- |
| Username / Password | generated per device |
| Domain | `SIP_DOMAIN` — the host's LAN address, from `.env` |
| Transport | UDP or TCP (TLS needs a certificate — not yet) |
| Authentication ID | blank; it is the same as the username |
| Registrar / Outbound proxy URI | `sip:<domain>:5060;transport=<udp\|tcp>` |

The provisioning QR points the fetch at `APP_URL` and registers the phone to
`SIP_DOMAIN` (the LAN address). Provisioning is deliberately local-only: the
config carries the device's SIP password in the clear, so it is never sent
across the internet.

Each device also gets an extension, numbered from **201**. They deliberately do
not start at 101: in the UK 101 is the police non-emergency number, and a child
who has learned to dial it should never reach a handset in the hallway instead.

Dial **600** for an echo test — the quickest way to confirm audio works in both
directions. **258** is the joke line (below) and **700** is voicemail.

The Grandstream HT801/HT802 are shown but marked *coming soon*: they need
auto-provisioning, which isn't built.

```bash
# prove a device's credentials work without touching a phone
docker exec twocans-php php /var/www/html/bin/sip-register-test.php 101
```

> **Docker networking matters here.** Asterisk sits on a bridge network (172.x)
> while phones reach it on the LAN address, so the generated transports set
> `external_media_address` / `external_signaling_address` from `SIP_DOMAIN`.
> Without those it advertises its container IP in SDP and audio goes nowhere.
> This is why moving the box means changing `SIP_DOMAIN` and restarting
> Asterisk, not just reloading it.

## Transcription

Call recordings are transcribed by a **Whisper model running on this box**. The
`whisper` service publishes no ports and is reachable only on the compose
network — the audio never leaves the machine, which is the promise the product
makes on its own login screen. It needs internet once to fetch the model, then
works offline.

It is a **purpose-built 720MB image** (`docker/whisper/`) carrying faster-whisper
and nothing else. The obvious off-the-shelf option,
`onerahmet/openai-whisper-asr-webservice`, is **8.19GB** because it bundles the
OpenAI reference engine — PyTorch plus CUDA libraries — alongside faster-whisper
and picks at runtime. With no GPU here, none of that is ever used.

```bash
docker exec twocans-php php /var/www/html/bin/transcribe.php   # one pass, by hand
docker logs -f twocans-transcriber                             # watch the worker
```

The `transcriber` service runs the same image as php-fpm with a different
command, polling for recordings without a transcript. It is separate because
Whisper takes seconds to minutes per call and a web request must never wait on
it; it claims each call in the database before starting, so running more than
one worker is safe.

Inference runs `int8` on CPU, roughly 3x faster than float32 with little
accuracy cost on speech this clean. `WHISPER_MODEL` defaults to `base`. `small` is noticeably better on noisy phone
audio at roughly three times the time. The container is capped at 4 CPUs so
transcribing can never starve Asterisk of the CPU it needs to keep RTP flowing —
choppy audio on a live call is a far worse failure than a transcript arriving a
minute late.

## Profile pictures

Contacts and phones can carry a photo — the design's own note is *"kids
recognise faces, not numbers"*. Tap the avatar (or the tin can on a phone) to
choose one.

Uploads are **never stored as received**. Every image is decoded, centre-cropped
square and re-encoded as a 512px JPEG, which:

- **strips EXIF, including the GPS coordinates a phone camera embeds** — these
  are pictures of children, and where they were taken has no business being in a
  file the app serves back out;
- proves the bytes really are an image, so a file that merely claims to be a
  JPEG can't be stored and later served as something executable;
- bounds the size, so one huge upload can't fill the disk.

Files live in `storage/photos`, mounted at `/var/lib/twocans/photos` —
**outside the docroot**, and served through `/?photo=<name>` behind the login,
so a child's picture is never fetchable by an unauthenticated request.

## The joke line

Dial **258** from any handset and a joke plays, picked at random and never the
same one twice running. Parents manage what's on it from the Joke line page.

That number is a setting, changed on the same page — pick whatever a child will
remember. It is refused if it would shadow an emergency or service number, a
handset's own extension, or a speed dial already given to somebody; and the
reverse holds too, so a speed dial or an automatically allocated extension can
never land on top of the joke line.

Uploads accept whatever a parent happens to have — a voice memo off a phone, an
MP3, an Opus or M4A file — and ffmpeg re-encodes each one to **8kHz, 16-bit,
mono PCM WAV**, which is Asterisk's native `wav`. Converting once on upload
beats transcoding on every call, and 8kHz loses nothing a narrowband handset
could have reproduced anyway. Loudness is normalised at the same time, because
a studio voice and a phone held at arm's length are otherwise minutes apart in
volume.

Each upload is queued for transcription like any other audio, so the page shows
what the joke actually is without playing it. **The transcript is editable, and
needs to be**: Whisper is reliably wrong about puns, since a homophone is the
entire mechanism of a children's joke — "a thesaurus" came back as "a SOSORUS".

Audio lives in `storage/jokes`, mounted read-only into Asterisk at
`/var/lib/twocans/jokes` and played by absolute path. It is deliberately not
under `/var/lib/asterisk` or `/var/spool/asterisk`: both are named volumes, and
nesting a bind mount inside one invites trouble.

The library is meant to be built up in batches, so importing the same clip
twice is caught rather than doubling its odds of coming up. Each joke carries a
SHA-256 of its *converted* audio — conversion is byte-for-byte deterministic, so
the same source lands on the same hash whatever filename or format it arrived
as, and a re-added folder is recognised for what it is.

```bash
# bring in a folder of audio files all at once; repeats are skipped
docker compose exec php php /var/www/html/bin/import-jokes.php /path/to/folder

# one-off, for jokes added before dedupe existed
docker compose exec php php /var/www/html/bin/backfill-joke-hashes.php
```

## Asks to call

When a child dials a number that isn't on the allowlist, the line says no — and
then invites them to say who they were trying to reach. That recording, plus how
many times they've tried, appears on the dashboard as a real request a grown-up
can approve.

Approving doesn't quietly add a number: it opens the contact editor with the
number and the child's own words filled in, with the contact switched **off**
until it's saved. Half a contact on the allowlist is worse than none.

Nothing about this touches the database mid-call. The recording is named after
the call's `uniqueid` and matched up afterwards, and the ask itself is derived
from blocked calls already in the call log — so a blocked call behaves the same
whether or not the app is running.

Inbound screening (an unknown number ringing *in*) needs a SIP trunk and isn't
wired yet; the outbound half above works today.

## Three-way calls

The interface problem with conferencing on a children's phone is that every
normal answer is wrong. A child can't hold a call, dial a second person and
press a key to merge them, and an ATA with a rotary handset has nothing to press
anyway. So a group isn't a call feature here — **it's a person**.

"Grandma & Grandad" sits in the contact list with its own speed dial and is
dialled exactly like Grandma. Everyone's phone rings, and everybody ends up in
the same conversation. The child's side of it is identical to any other call.

Members are other contacts rather than raw numbers, so a group can only ever
contain people who are already allowed — there's no way to reach somebody new by
putting them in one. Groups can't contain groups either, which would be a neat
way to build an accidental loop.

Members are rung with `Originate`, not `Dial`: `Dial` with several targets
connects whoever answers first and hangs up on the rest, which is ring-any, not
a conference. Latecomers can still join.

There are **no announcements**. `announce_join_leave` sounds like what you'd
want, but ConfBridge implements it by recording each participant's name on the
way in — the hotel-PBX ritual this product exists to avoid. It sounds like an
ordinary phone call instead: when Grandma says hello, the child knows she's
there.

A parent joining a call in progress is a separate thing that already works —
see *Listening in*, whose "Join" mode barges into the existing call.

## Asterisk's sound prompts

The Asterisk image ships **no sound files at all**. Until they're installed,
every stock prompt silently plays nothing — the blocked-call message, the
voicemail prompts, "nobody is available" — and ConfBridge won't admit anyone to
a group call, because it can't open the file it wants to play them.

`install.sh` downloads the core ulaw set (~10MB) into the Asterisk volume. ulaw
because it's what the phones use, so it plays without transcoding. To do it by
hand:

```bash
curl -fsSL -o /tmp/s.tgz https://downloads.asterisk.org/pub/telephony/sounds/asterisk-core-sounds-en-ulaw-current.tar.gz
mkdir -p /tmp/en && tar xzf /tmp/s.tgz -C /tmp/en
docker compose cp /tmp/en/. asterisk:/var/lib/asterisk/sounds/en/
```

## Retention

Recordings and transcripts are deleted once they pass the household's window,
set on the call log page: a week, 30 days, 90 days, a year, or forever.

What expires is the **content**, not the record. The audio file is deleted and
the transcript blanked, but the call log entry stays — a parent can still see
that Grandma rang on the 3rd of March a year later, they just can't listen to
it. That is the useful half of the record and the harmless half.

The sweep runs on a backend page load, at most once an hour, and never on the
API polls or file downloads — those exit earlier in `index.php`, so the phones
page polling every two seconds doesn't trigger it. There is no cron job on
purpose: this is a box in somebody's house, and a scheduled job is another
moving part to install, monitor and explain.

Upgrading never deletes anything. Migration 016 pins a household that already
has call history to *forever*, so a policy only ever takes effect once somebody
chooses one. A brand-new install has no history to lose and starts at 90 days.

Note that `docker/asterisk/recordings` is mounted **writable** into the php
container for this — Asterisk still owns writing recordings, but only the app
knows when one is past its keep-until date.

```bash
# see what would go, without doing it
docker compose exec php php /var/www/html/bin/retention.php

# sweep right now
docker compose exec php php /var/www/html/bin/retention.php --run
```

## Listening in

A parent cannot listen from the browser. ChanSpy is an Asterisk application that
runs **on a channel**, so somebody has to be on a phone to hear anything —
listening therefore rings a handset in the house and attaches it to the call in
progress. Doing it in the browser would mean WebRTC (a WSS transport, SRTP and a
JS SIP stack), which is far more work than the feature is worth today.

The modal offers the design's three modes, mapped onto ChanSpy options:

| Mode | ChanSpy | Who hears the parent |
| --- | --- | --- |
| Listen | `q` | nobody — the child hears no click either |
| Whisper | `qw` | only the child |
| Join | `qB` | everybody on the call |

**Every listen is recorded.** The UI promises a family that listening is noted,
so it is written to `listen_events` the moment it starts — before anyone hears
anything — and merged into the call record when the CDR lands. Asterisk writes
its CDR at hangup, which is after listening begins, so the note has to survive
that gap rather than depend on the call ending normally.

## Checking Asterisk

```bash
docker exec twocans-php php /var/www/html/bin/check-asterisk.php
```

Verifies, from inside the PHP container and using the app's own env vars: ARI
auth (and that anonymous access is refused), AMI login/ping/reload, that the SIP
transport is actually bound, and that PHP can write to the shared config dir.
Exits non-zero on failure.

> The SIP-transport assertion exists because that failure is invisible: Asterisk
> rejects the *entire* `pjsip.conf` if a wildcard `#include` matches no files,
> leaving no transport — while ARI, AMI and every other check keep passing. The
> `generated/` directory must therefore always contain at least one `*.conf`
> file whose name does not start with a dot.

## The name the outside world uses

Home broadband addresses change without warning, usually in the middle of the
night, and nothing tells you. twocans keeps a name pointed at the house so
`phone.example.com` always means this box — whatever address the ISP has
assigned it this week.

There are two halves, both on the **Phone line** screen under *"Where the outside
world finds you"*:

- **The name that points here** — the external address. Set it however you like:
  a Cloudflare-managed name, another dynamic DNS service, or a static IP you keep
  updated yourself. This name is what the HTTPS web UI and its certificate use.
- **Keep it updated with Cloudflare** (optional) — if you use Cloudflare for DNS,
  twocans can keep that name's record updated automatically. Give it the domain
  and an API token; it verifies the token, creates or adopts the record, and
  keeps it right. Skip this if you use another DDNS provider or have a static IP.

The check runs **inside the app's own container**, once a minute — not in a
second container and not in cron, so there is nothing extra to install. Each
pass is a fresh short-lived PHP process; a wedged run costs one minute, not a
service. Logs go to `docker/php/log/ddns.log` and `docker logs twocans-php`.
`DDNS_WATCH=0` in the `php` service's environment turns the checks off.

For the Cloudflare option, the token needs the bare minimum, scoped to the one zone:

| Permission | Why |
| --- | --- |
| Zone → **Zone** → **Read** | list the zone and its records |
| Zone → **DNS** → **Edit** | create and update one record |

It is encrypted at rest with `APP_KEY`, stored in the `dynamic_dns` table, and
used for nothing else.

From the command line:

```bash
docker exec twocans-php php /var/www/html/bin/ddns.php --status    # what is stored
docker exec twocans-php php /var/www/html/bin/ddns.php             # check once
docker exec twocans-php php /var/www/html/bin/ddns.php --force     # check, ignoring the guard
```

Things worth knowing before you rely on it:

- **It points a name, and that is all.** It does not open ports or change how
  calls behave. Port forwarding and `external_*_address` are separate jobs.
  (Calls are local to the house, so neither is currently needed for SIP.)
- **The record is unproxied** (grey cloud, not orange). The orange cloud only
  speaks HTTP and would hide your real address from everything else — SIP and
  RTP would not know where to go.
- **TTL is 60 seconds**, Cloudflare's floor for an explicit TTL. A changed
  address therefore settles within about two minutes across the internet's
  caches, which is about as good as dynamic DNS gets.
- **It follows your egress address.** If this box sits behind a VPN or
  Cloudflare WARP, the public address it learns is the VPN's — the record will
  point there, not at your router. Run it on the network it should describe.
- **CGNAT breaks the assumption.** If your ISP shares one public address between
  customers, a name pointing at that address reaches your ISP, not your router,
  and no dynamic DNS can fix that.
- **Turning Cloudflare off leaves the record alone.** A name a household has given
  out — to a school, on a bookmark, to a phone — should not silently disappear.
  It pauses the Cloudflare checks but remembers the setup, so turning it back on
  does not ask for the token again.
- **No account ID is needed.** twocans looks the zone up by name, so all it ever
  needs is the token; the zone ID is fetched and cached automatically.

## HTTPS

The web UI is served over HTTPS from the moment the box starts, using a
**self-signed certificate** generated automatically — it works immediately, but
browsers show a warning until a real certificate is installed.

To replace it, open **Phone line → "Where the outside world finds you" → HTTPS
certificate** and press **Get a Let's Encrypt certificate**. That runs certbot
inside the nginx container against the external address, installs the issued
certificate, and reloads nginx. It proves ownership with a **DNS-01** challenge
through the Cloudflare token (so no inbound port 80 is needed), plus the name
already pointing at this box, which the dynamic DNS step above sets up.

- Certificates and certbot state live in `docker/nginx/certs/` and
  `docker/nginx/acme/` (shared with the app).
- The HTTP listener is deliberately not redirected to HTTPS, so phones and
  browsers on the home network keep using plain `http://<LAN>:8083`.
- Set `HTTPS_PORT` in `.env` if something else already holds 443.

## What's next

In rough order of how much they matter:

1. **An outside line.** The trunk wizard connects Twilio or SIP.IO, verifies the
   credentials, encrypts them with `APP_KEY` and generates the trunk config —
   but none of it has been tested against a real account. Inbound reachability
   and a real test call are the open work.
2. **SIP over TLS.** HTTPS for the web UI and provisioning links is done (see
   above); SIP itself still runs plain UDP/TCP. TLS for calls needs a
   `transport-tls` and a certificate Linphone will accept.
3. **ATA auto-provisioning.** The Grandstream HT801/HT802 are shown but marked
   *coming soon* — the wizard's waiting step is still a timer.
4. **Ask-to-call.** The dashboard queue is the last screen running on demo data;
   approving a request should add the number to the allowlist.
5. **Retention.** Recordings, voicemail and transcripts grow without limit and
   nothing prunes them. These are recordings of children — a default retention
   window is a feature, not housekeeping.
6. **Ring-both failover**, guardian invitation emails, and authentication on
   extension 500 (record the greeting), which today anyone in the house can
   dial.

Automated tests now cover the safety-critical paths (see "Tests" below). The
remaining gap is integration coverage against a real Asterisk and provider.

## Tests

Run them inside the php container:

```bash
docker compose exec php php /var/www/html/bin/test.php
```

A tiny runner (`bin/test.php`, no PHPUnit, matching the project's no-build-step
rule) discovers `backend/tests/*.php`. The unit suite covers E.164
normalisation, dial-plan prefix rules, presentation helpers, password policy and
the role matrix, and the dialplan's "who can call when" logic
(`PjsipConfig::renderReachRule` / `windowCondition`). A structural test proves
every action in `src/actions.php` is listed in `src/Permissions.php`, so a new
action cannot ship unguarded.

## Backups & system health

The **System** screen (Owner/Admin, under the account menu) shows a live health
check of Asterisk (ARI/AMI), MariaDB, Whisper, disk space and the generated
config directory, and manages backups. `bin/check-asterisk.php` prints the same
checks from the command line — they share `src/SystemHealth.php`, so the two can
never drift apart.

Backups bundle the database plus every recording, voicemail, photo and joke
into one tarball in `storage/backups/`:

```bash
docker compose exec php php /var/www/html/bin/backup.php          # create
docker compose exec php php /var/www/html/bin/backup.php --list
```

Restore is Owner-only. From the System screen, pick the downloaded `.tgz` and
type `RESTORE` to confirm — both the permission and the typed confirmation are
enforced server-side, and a safety dump of the current database is written
before anything is touched. The command-line form is also available:

```bash
docker compose exec php php /var/www/html/bin/restore.php --dry-run twocans-….tgz
docker compose exec php php /var/www/html/bin/restore.php twocans-….tgz
```

A backup only restores onto a box with the same `APP_KEY`: the trunk and DDNS
tokens inside it are encrypted with it.

## Notifications

twocans can email a grown-up when something needs attention, and heartbeat an
[Uptime Kuma](https://uptime-kuma.com) **Push** monitor so the line shows up on a
home monitoring dashboard. Configure both on the **Notifications** screen
(Owner only):

- **Mailgun** — email alerts for new ask-to-call requests, a phone going
  offline, and low call credit. The API key is encrypted at rest with `APP_KEY`.
- **Uptime Kuma** — paste a Push monitor's URL; twocans GETs it every minute,
  and when the heartbeats stop Kuma marks twocans down and alerts through its
  own channels.

The notifier runs inside the php container once a minute, alongside the dynamic
DNS check:

```bash
docker compose exec php php /var/www/html/bin/notify.php --status
docker compose exec php php /var/www/html/bin/notify.php          # run once
```

Each event is emailed only once: new asks are watermarked, offline detection
fires only on an online→offline transition, and low credit only on the first low
reading (re-armed when credit recovers).

## Grandstream GHP621

The GHP621 hotel phone is a provisionable device. Add one from the Phones
screen and enter its **MAC address**; the phone then fetches its whole setup —
SIP account plus its **hotkeys** — from
`http://<LAN>:8083/grandstream/cfg{MAC}.xml` over HTTP basic auth (username
`twocans`, password shown on the phone's page). Point the phone's Config Server
Path at that address in its web UI and reboot it.

Hotkeys are one-touch speed dials: on the phone's detail page, pick which
contact (or service number, like voicemail `700`) each physical key dials. A key
can only ever be given a number a child is allowed to reach.

### Remote phonebook

The allowlist is also published as a remote phonebook for IP phones, in two
vendor formats (both behind the same HTTP basic auth):

- Grandstream — `http://<LAN>:8083/phonebook/grandstream.xml`
- Yealink — `http://<LAN>:8083/phonebook/yealink.xml`

Point the phone's remote-phonebook setting at the right one (Grandstream uses a
phonebook server path, Yealink a remote phonebook URL). Only contacts the child
may call out to appear — groups, blocked contacts and blank numbers are left
out.

> The SIP account P-codes are standard across Grandstream models; the **hotkey
> P-codes** (`GrandstreamProvisioning::HOTKEY_PCODES`) are the GHP series' own
> and are marked `TODO(verify)` — confirm them against the official GHP621
> config template before relying on them.

## Credit

UI recreated from the *Open Source Tincan Phone* design handoff. The prototype's
`support.js` runtime is a prototyping tool and is deliberately not carried into
this codebase.
