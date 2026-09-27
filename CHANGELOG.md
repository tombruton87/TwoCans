# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Initial public release.
- Business Source License 1.1 (`LICENSE`) with Apache-2.0 as the Change License;
  free for household / non-commercial self-hosted use.
- One-command controls via `Makefile` (`make up`, `make migrate`, ...).
- Security policy (`SECURITY.md`), contributing guide (`CONTRIBUTING.md`), and
  code of conduct (`CODE_OF_CONDUCT.md`).
- Group calls ask whoever answers to **press 1 to join**, so a mobile's voicemail
  can no longer talk into the call. The house's own recording of that question
  is set on the **Greetings** screen, and each group can have its
  own ("The kids are calling the grannies — press 1 to join"); without either,
  Asterisk's stock "press 1 to accept this call" plays. Migration 032.
- **More than one number on the phone line.** The number box in the connection
  wizard takes several, separated by spaces (or commas). The first stays the
  caller ID for outgoing calls; each one is checked with the provider — on the
  account and, for Twilio, attached to the trunk — and calls to any of them are
  handled the same way. Migration 033. Each number can be pointed at its own
  phone under **Who these numbers ring** (migration 034 carries the line's
  existing choice over to its main number), and the call log and dashboard say
  which number an incoming call came in on. A phone a number is pointed at
  also calls out from that number; every other phone uses the main one.
- **Home Assistant integration** over MQTT with discovery (account menu → Home
  Assistant): a twocans device and one per phone appear in HA by themselves.
  Sensors for calls today, active calls, unheard voicemails, decisions waiting,
  the phone line, credit and bedtime; per phone, online, call state and who
  with, adult mode and minutes today. Switches for bedtime, taking messages and
  each phone's incoming and outgoing calls; buttons for every announcement and
  to ring a phone or hang it up; an Announce text box spoken on the phones by
  HA's own text-to-speech. Event entities for calls ringing, answered and ended,
  unknown callers and kids' asks, limits reached and bedtime starting and
  ending. A long-running bridge (`bin/homeassistant.php`, started by the
  container) keeps the connection, with a last will so HA shows twocans
  unavailable if it stops. Adult mode is shown but not switchable from HA.
  Migration 042 (settings values may be longer than 255 characters).
- **Adult mode** for a grown-up's own phone on the line: every restriction is
  removed, both ways — it can call any number at any time and anyone can ring
  it; the call list, dial plan rules, bedtime, hours and call limits don't apply.
  Switched on only through an "are you sure?" confirmation that spells that out
  (and refused by the server without it), and hard to miss while on: a red
  banner and badge on the phone's page, a badge in the phone list and on the
  dashboard. Enforced by Asterisk. Migration 041. Calls on a phone in adult mode
  are not recorded or transcribed: incoming calls now start recording when a
  phone answers, so it is the phone that picks up that decides.

- **Install it as an app**: "Add to Home Screen" on a phone now opens twocans
  full-screen with its own icon (a web app manifest, icons from the two-cans
  logo, and a small service worker). Pages are never served from a cache — only
  the look of the app is kept — and when the box can't be reached a friendly
  "can't reach your line" page shows instead of the browser's error. Needs the
  HTTPS address.
- **Sign in with Face ID**: passkeys (WebAuthn) — Face ID, Touch ID or a
  fingerprint instead of a password. Set up per device on Family & guardians;
  "Sign in with Face ID" on the sign-in page. Discoverable passkeys with user
  verification required, verified on the box with PHP's OpenSSL; only works on
  the HTTPS address, never a bare IP. Migration 040.
- **Announcements**: buttons on the home page that page the phones with a
  recorded message — "dinner's ready", "ten minutes till bedtime". Each one says
  which phones it goes to, whether they ring or pick up by themselves (intercom
  headers for desk phones that allow auto-answer; anything else just rings), and
  whether to play it twice. Each also has a secret trigger URL,
  `/hook/announce/<token>` (GET or POST), so Home Assistant, IFTTT or Uptime Kuma
  can press it. Set up under the account menu → Announcements. Migration 038.
  Each button's icon comes from a picker with Font Awesome Free's ~2,000 icons
  (searchable, by category) or a row of emoji. Font Awesome Free 7.3.1 is bundled
  under `backend/assets/vendor/fontawesome` (icons CC BY 4.0, fonts SIL OFL 1.1,
  code MIT — licence alongside), so it works with no internet. Migration 039.
- **Weekly schedules** for bedtime, each phone's hours and a contact's custom
  call window: rows of days with their own times ("weekdays 19:30–07:00, Fri and
  Sat 21:30–08:30"), edited from the dashboard's Bedtime card, the phone's page
  and the contact editor. A time that runs past midnight belongs to the night it
  starts. Bedtime's times can now be changed at all, and "Custom hours" on a
  contact finally has an editor. Migration 035.
- **Call limits** per phone: the longest single call and a daily total. A beep a
  minute before an outgoing call's limit, then it ends; a phone that has used
  its day can't call out and doesn't ring. Enforced by Asterisk, so they hold
  with the app down. SOS and "always put through" contacts and emergency numbers
  are never limited. "Out of phone time" is a new recording on Greetings.
- **Who a group call reached**: the call log and dashboard say "Anna joined ·
  Tom didn't answer" for group calls made from now on. Migration 036.
- **More notifications**: an email the minute a phone dials an emergency number,
  one when somebody not on the list leaves a message, and an optional weekly
  summary on Sunday evening. The notifier now brings the call log up to date
  itself, so alerts no longer wait for someone to open the app. Migration 037.
- A **Greetings** screen (account menu → Greetings) gathering everything the line
  says out loud, grouped by who hears it, each with where it plays and what the
  standard one says. Alongside the quiet-time message, the group greeting and
  each phone's refusal, it adds recordings for Asterisk prompts that were not
  editable before: the house voicemail greeting, the house default for callers
  not on the list, and what a child hears when a number isn't allowed, when asked
  who they were trying to call (the stock prompt asked for their name and the
  pound key), when it's not the right time, and when there's no phone line.
- A per-person **"Always put through"** switch on the People screen, for the
  people who have to reach the house whatever the hour — Mum and Dad, a carer,
  the on-call number. Their call skips bedtime, the hours set on them and the
  hours set on each phone, so it rings a handset that is otherwise off for the
  night; an SOS contact keeps its narrower meaning, skipping bedtime and their own
  hours but still respecting each phone's opening hours. Inbound only,
  deliberately: it is about them reaching the children, not about what a child may
  dial. Existing people are unaffected — the column defaults to off.
- A **quiet-time message**: record up to 30 seconds for the whole house on the
  dashboard's "Quiet-time message" card. Callers who reach the line while
  bedtime mode has it asleep hear it instead of the stock voicemail greeting, and
  pressing **5** hands them to the joke line; pressing nothing still leaves the
  house mailbox. It lives in the refusals volume (`storage/refusals/quiet`, moved
  with `QUIET_MESSAGE_PATH`), so an existing install needs no new mount and no
  container recreate.
- **Screening for callers nobody recognises**: a number that isn't on the call
  list still never rings a phone, but the refusal can now end at the house
  mailbox instead of a hang-up, so a wrong number and a grandparent's new mobile
  can be told apart after the fact. What they leave is listed on the dashboard
  under **People we don't know** — number, when, how long, transcript and
  recording — with **Add to call list** (which opens the contact editor with the
  number filled in and the contact switched off until it is saved) and **Junk**
  to clear it. The decision is recorded on the voicemail row
  (`resolution`/`resolved_by`/`resolved_at`, migration 031), so it survives a
  restart and a new message from the same number arrives as a fresh card. On by
  default; the switch in the card's header turns it off and the line hangs up as
  it always did.
- **Grandstream HT801 and HT802 adapters**, so an ordinary corded phone can be
  one of the house's phones. Add one from **Add a phone** with the MAC address
  from its label; twocans serves its settings from the same provisioning
  address as the GHP621, and the phone's page says what to type into the
  adapter. Each HT802 socket is a phone of its own, with its own rules — name
  both in the wizard, or add the second socket later from the first one's page.
  A socket with no phone is switched off in the file. The MAC is now unique per
  socket rather than per phone (migration 043).
- **Say who's calling.** A phone with no screen gives no clue who's ringing,
  so a phone with this switched on plays the caller's name when it's picked
  up — "It's Nana!" — and then connects the call; the caller hears ringing
  until then. Each person gets a short clip in their editor: record one,
  upload one, or have Home Assistant's voice say it when text-to-speech is set
  up there. On by default for the adapters and the GHP621, off for Linphone,
  which shows the name and photo already; the switch is on the phone's page and
  in Home Assistant. The clips are listed on **Greetings** too. Played before
  the recording starts, so it isn't in it. Migration 044.
- **Self-service links.** From a person's editor, **Make a link** and send it
  to them — **Send on WhatsApp** or **Send as a text** opens a message to their
  number with the link already written, or Copy it. Opening it needs no account:
  they add their own photo — **Take a photo** opens the phone's camera (or the
  webcam, on a computer), or choose one — and say their own
  name — recorded right in the browser, or a recording they choose — and it's
  on the kids' phones straight away. The link only reaches that one person's
  photo and name, never a group; it works for 7 days, a new one replaces the
  old, and **Stop it** ends it early. The editor shows when they used it. The
  link uses the house's outside address when it has a proper certificate, and
  says so when it would only work at home. Migration 045.
- **Opening the router.** The Phone line screen lists the ports the outside
  world needs — the app and self-service links (443), calls from the phone
  line, their sound (the RTP range), and optionally phones away from home —
  and can ask the router to open them itself, by UPnP or NAT-PMP. Openings
  are leased and renewed every half hour by the minute worker, so they lapse
  on their own if twocans goes away; unticking a group or switching it off
  closes only what twocans opened. It says when a port is already taken, when
  the router gives a different port number (no use to SIP), and when the
  router's own outside address is private — another router or the provider
  in front, where opening ports won't help. The router is asked directly
  rather than by broadcast, which doesn't come back through Docker's network;
  its address is guessed and can be set.

### Fixed

- The Phone line screen said no SIP or RTP ports need forwarding. Calls from
  the phone line do need them; it now lists every port and what it's for.

- Audio recorded in a browser (WebM from the phone's own recorder) was turned
  away as "not audio we can play", because it carries no length in its header.
  Its length is now measured by decoding it — which also fixes such files
  chosen for jokes, greetings and the rest.
- The sign-in page showed an empty red error box under **Sign in with Face
  ID**: the box's own styling overrode it being hidden.
- The GHP621's provisioning file now gives the SIP server with its port. It
  gave the address alone, so the phone tried 5060 and never signed in on a box
  where twocans uses another port.

- **Call limits on incoming calls** now apply. The step run when a phone
  answers was addressed the way Dial's U() doesn't accept, so it never ran: no
  per-call cap on incoming calls, and their talk time wasn't timed. It has a
  context of its own now.
- A phone's **Outgoing calls** switch now actually stops it calling out. It was
  saved but never reached the dialplan; a phone with it off now hears the
  "not allowed" message instead. Emergency numbers, the house's own phones and
  the service numbers still work.

### Changed

- A caller who reaches the house while the line is quiet — bedtime mode, or
  outside the hours set on the contact they're after — is now told why in the
  household's own voice and offered the joke line, instead of being dropped
  straight into the house mailbox. Both reasons share one branch of the dialplan,
  so a phone's own hours and bedtime behave the same way. A caller who isn't on
  the list is refused before either of them is considered, and the SOS contact
  still rings through.
- The SOS contact's hint in the person editor now says what it does — skips
  bedtime and their own hours — so it reads as the narrower switch beside "Always
  put through".
- The dashboard's **Asks to call** and **Callers we don't know** cards are one
  card now, **People we don't know**, because the decision behind them is the
  same one: a number the household has never agreed to. A child asking to call a
  new number and a caller nobody recognises who left a message are listed side by
  side, newest first, each row tagged *Wants to call* or *Rang us* and offering
  the same two buttons — **Add them**, which opens the contact editor with the
  number filled in and the contact switched off until it is saved, and **Not
  now** to clear the row. Nothing about how either is decided has moved: a
  dismissed ask still drops its voice note, an unknown caller's message is still
  kept in the mailbox, and the switch that decides whether unknown callers can
  leave one at all stays in the card's header. The records themselves stay in
  their own tables — an ask is a blocked attempt, a message is a voicemail the
  mailbox screen and the phone's message light both read — so the card is a view
  of both rather than a merged store.
- **People we don't know** on the dashboard is now callers only — somebody who
  rang in and left a message. The numbers a child tried to call moved to a
  **Numbers the kids tried** log on the call log, one card per number with the
  same two buttons. Both leave out anyone who has since been added as a contact.
