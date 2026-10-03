# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.7] - 2026-10-03

Many more phones — Yealink, Poly, Cisco and Fanvil — each with the settings it
actually has; a line full of things to call, from games and the radio to Santa;
diagnostics to send when a phone won't come online; and an installer that
works on far more Linux, and says what to do when it can't.

### Added

- **Yealink desk phones: T31G, T33G, T42U/T42S, T43U, T44U/T44W, T46U,
  T48U/T48S, T53W, T54W, T57W and T58W** (untested). Point the
  phone at twocans (the Setup tab has the steps) and it fetches its line,
  its message key ringing 700, the household's time and phonebook, and its
  speed-dial keys — the line keys after its own, named on its screen.
  Announcements are answered by themselves, and "Pick up to ring a
  grown-up" works. Its Phone settings: how loud it rings (fixed, so it can't
  be turned down to nothing — or left to the phone), call waiting, Do Not
  Disturb and call forwarding on the phone, its phonebook, key tones,
  whether the screen stays lit, a clock screen saver on the colour ones,
  and calls to an IP address.

- **A wallpaper of your own on a Yealink colour screen** (T33G, T44U,
  T46U, T48U/T48S, T54W, T57W and T58W) — a family photo, a pet, a favourite drawing. Upload it on the
  phone's Phone settings tab: it's cropped to fill that screen exactly,
  stripped of where it was taken, and sent to the phone, whose key labels
  go part see-through over it. "Back to its own wallpaper" puts the
  built-in one back and deletes the picture. The phone fetches it behind
  the same password as its settings, and can fetch nothing but a phone's
  wallpaper that way.

- **More Yealink cordless bases: the W70B (up to 10 handsets) and the older
  W52P (up to 5)** (untested), set up like the W60B, each with the settings
  it has. Adding a Yealink now picks the base, not the handset.

- **Yealink cordless phones: W56H handsets on a W60B base.** Each handset
  is a phone of its own in twocans — up to eight on one base — and the base
  fetches everything from twocans: each handset's line, its name on screen,
  its message key, the household's time zone, and a phonebook of everyone it
  can call. Point the base at twocans once (the steps are on the phone's
  Setup tab); a base that asks before it's added shows up under "Found on
  your network". Handsets only answer an announcement by themselves when one
  is alone on its base, so they're rung like a call.

- **Yealink handsets answer announcements by themselves** (W60B and W70B,
  firmware V85): with a beep first, silently, or ringing to be answered —
  each handset its own choice. So the walkie-talkie works to one too.

- **A ringtone for each Yealink handset, and a fixed ring volume** (W60B and
  W70B). Pick one of the handset's own rings on its Phone settings tab —
  each handset can have its own — and twocans asks for it on every call to
  it. The ring volume can be fixed for the base, so it can't be turned down
  to nothing.

- **Phone settings for every kind of phone, from what it can do.** A W56H
  gets its own: lift off the charger to answer, hang up on the charger,
  call waiting, how an intercom from another handset is answered, key and
  battery tones, its lights, whether the screen stays lit, a big clock when
  idle, 12- or 24-hour time, wallpaper and colours, and calls straight to its
  IP address. Most belong to the base, so changing one changes it on every
  handset there — the page says which don't. Desk phones keep theirs.
  HT801 and HT802 adapters get theirs too, for the corded phone in each
  socket: how loud callers sound and how loud it's heard, caller ID for a
  phone with a screen, its message lamp, a tone when it's left off the hook,
  call waiting, whether tapping the hook puts a call on hold, the adapter's
  own star codes (off, so *78 can't quietly turn on Do Not Disturb), and
  "Pick up to ring a grown-up". The line itself — impedance, caller ID style
  and ring — now matches your country, so a UK corded phone shows who's
  calling. SSH, the *** keypad menu, firmware checks and who may change its
  settings are the adapter's, shared by both sockets.

- **Rotary phones on an HT801 or HT802.** Tick "It's a rotary phone" on its
  Phone settings tab and the adapter hears the dial's clicks as numbers (by
  your country's standard), waiting a little longer after the last one —
  there's no # on a dial to say "that's all". The wait can be set for any
  corded phone. Its contact sheet leaves out the lines a dial can't use —
  messages, games, times tables and the kitchen timer all need keys pressed
  during the call — and says so.

- **Poly (Polycom) VVX desk phones** — the 150, 201, 250, 300/310, 350,
  400/410, 450, 500/501 and 600/601. Point the phone's provisioning server
  at twocans (the Setup tab has the steps) and it fetches everything: its
  line, its message key ringing 700, the household's time, and its
  speed-dial keys — the line keys after its own, chosen on its Speed-dial
  keys tab and named on its screen. Announcements are answered by
  themselves. Its Phone settings: call waiting, the speakerphone, Do Not
  Disturb and call forwarding on the phone (off), keeping the earpiece
  volume between calls, how bright the idle screen is, a screen saver,
  calls to internet addresses, and editing its contacts on the phone.

- **Fanvil GA10 adapters**, for one corded phone — **untested**. Fanvil
  hasn't published the GA10's own settings template, so its settings follow
  the format and names of Fanvil's newer phones: its line, the household's
  time, "Pick up to ring a grown-up", and Phone settings for call waiting,
  transfer and three-way calls with the hook, Do Not Disturb on the adapter,
  the wait after the last number, how loud callers sound and are heard,
  calls to an IP address, Telnet and firmware upgrades. Its caller ID style
  and line impedance are left as the adapter has them. It's marked
  "Untested" when adding it and on its page, with a link to report how it
  goes.

- **An "Untested" mark** for any model twocans sets up from its maker's
  documentation alone, until someone confirms it on a real one — for now the
  Yealink desk phones and W70B and W52P bases, the Cisco SPA112, SPA122,
  ATA 191 and ATA 192, the Poly VVX phones and the Fanvil GA10. (The Yealink
  W60B has been tried on a real one, and isn't marked.) It shows on the model and its maker when
  adding a phone, and on the phone's page, which says what that means and
  where to report how it goes.

- **Cisco SPA112, SPA122, ATA 191 and ATA 192 adapters**, for two corded
  phones, each a phone of its own. (The SPA122 has a router built in: its web
  page is reached by plugging a computer into its Ethernet port, and the
  Setup tab says so. An ATA 191 or 192 must be the Multiplatform "-3PW"
  version; the Enterprise one only talks to Cisco's own call manager.)
  Type one line into the adapter (its Profile Rule — the Setup tab has it)
  and it fetches everything else from twocans: both lines, the household's
  time, caller ID and line settings for your country, and its own Phone
  settings — call waiting, hold on a tap of the hook, how long it waits
  after the last number, how loud callers sound and are heard, its message
  lamp, its star codes (off, so *72 can't forward a child's calls), and
  "Pick up to ring a grown-up". They need touch-tone phones: neither can
  hear a rotary dial.

- **The installer works on more Linux, and says what to do when it can't.**
  It installs Docker the way that suits the machine — Docker's own script on
  Ubuntu, Debian, Raspberry Pi OS and Fedora; Docker's own packages on Linux
  Mint, Pop!_OS, Rocky and AlmaLinux; the distribution's own on Arch,
  Manjaro, openSUSE, Alpine and Kali — and offers to install any missing
  tools with the right package names. It starts services on OpenRC as well
  as systemd, runs as root where there's no sudo (a Proxmox container, say),
  and explains a 32-bit Raspberry Pi rather than just refusing it. Every
  stop now says what went wrong, what to do, step by step, and where to get
  help; a step that fails explains what its output usually means (no disk
  space, Docker Hub's download limit, no internet…); and anything unexpected
  says so, instead of stopping without a word.

- **Diagnostics for every phone**, to send to whoever's helping when one
  won't come online or a setting won't take — on its Setup tab, as a
  download or to read first. One text file: what twocans knows about it,
  how it's signed in (and what it says it is), what it's asked twocans for
  lately, its chosen settings, and the exact settings file it's handed.
  Passwords and keys are taken out, and names, phone numbers, email, MAC
  and IP addresses each get a stand-in — the same each time — so it can go
  on a GitHub issue.

- **"Look for phones" finds every brand twocans sets up** — Grandstream,
  Yealink, Poly, Cisco and Fanvil — by the maker's registered MAC prefixes
  (from the IEEE registry), and reads each one's model off its own web page.
  One that won't say its model can still be added: twocans offers its
  maker's models, with its MAC filled in. A Cisco is only listed when it
  says it's one of its adapters, so the router doesn't turn up.

- **Adding a phone starts with who makes it** — an app, Grandstream, Poly,
  Cisco, Fanvil or Yealink — then the model.

- **The house's messages on 701**, for phones allowed to hear them — a
  grown-up's, say — switched on for each phone under Rules. Off for every
  phone to begin with: a message left for the house may not be for a child.

- **Games, on 4263 (G-A-M-E).** A menu of games played on the keypad:
  times tables, sums (up to 10 or 20), number bonds ("what goes with 7 to
  make 10?"), guess my number (1 to 100 — "higher!", "lower!") and animal
  riddles. A cheer for a right answer, the answer for a wrong one, and a
  score at the end. 246 goes straight to times tables. The Games page says
  how each phone is getting on, and sets the numbers, the tables in a mix,
  what sums and bonds go up to, and how many questions a game.

- **The radio, on 7234 (R-A-D-I-O).** Upload songs — up to 20 at a time —
  and dialling it plays them one after another, shuffled, until it's hung
  up; each call carries on where the last stopped. # skips to the next song,
  5 pauses, 6 and 4 go forward and back. Shuffled, or in your own order —
  drag a song by its handle (or use the arrow keys) to change it. Rename
  songs, switch them off, or delete them, from the menu's The radio page.
  Stations: up to five playlists, offered from a menu ("Press 1 for… party
  songs", in your own voice), or straight away on a phone with a favourite;
  ★ goes back to the menu. At bedtime it can play only the songs marked
  calm, or be off, and a sleep timer says night night after a while.

- **Sleeps till Christmas, on 1225.** Santa counts down: "Ho ho ho! Hello
  there, it's Santa! There are 57 sleeps until Christmas!" One more sleep on
  Christmas Eve, and on Christmas Day, his message — Santa's own, or one a
  grown-up records or uploads, with the children's names in it. And, if it's
  switched on, Santa rings the children's phones on Christmas morning at the
  time you choose. On the Games page.

- **Desk phones made for a child's room.** On each one's page: how loud it
  rings (locked there, so it can't be turned to nothing), and "pick up to
  ring a grown-up" — lift the handset, press nothing, and after a few seconds
  it rings who you chose. The page says when its handset has been left off
  the hook, and when it last started up, which the phone now tells twocans.
  And on every desk phone: no light when idle and a steady one for a new
  message, no start-up beep, no call waiting; Mute no longer quietly turns
  on Do Not Disturb; calls not meant for it, or made straight to its IP
  address, are refused; SSH and the keypad's settings menu are off; and it
  only ever takes settings from twocans, with no surprise firmware. Each of
  these is a switch on the phone's new Phone settings tab — what's best for
  a child's phone to begin with, changed for one phone if it suits — along
  with how long it waits before its "put me back" tone when left off the hook.

- **Notifications on your phone and computer** (Web Push), even with twocans
  closed — no email needed. On the Notifications page, "Notify me on this
  device": an emergency number dialled (it stays on screen), a message from
  someone not on the list, a number a child tried, a phone gone offline or
  its handset left off the hook, credit running low. Tap one to open the
  page it's about. Each device can be sent a test, or removed. Encrypted for
  each device, signed with this box's own key (RFC 8291 and 8292). On an
  iPhone, add twocans to the Home Screen first.

- **Silly voices, on 7455 (S-I-L-L).** Say something after the beep and hear
  it back as a chipmunk, then a giant. Nothing's kept.

- **Walkie-talkie, on 9255 (W-A-L-K).** Pair a phone with another on its
  page; dialling it — or a hotkey for it — makes that phone answer by itself
  on speaker, with a beep, to talk both ways. Never into a call, not at
  bedtime, and not to a paused phone. Both, with the timer and the clock, on
  the menu's Handy lines page.

- **A kitchen timer, on 2463 (C-H-I-M-E).** Dial it, type the minutes and
  #, hang up — and the phone rings when they're up: "Ding ding! Your timer's
  finished!" On time to the second. One a phone; 0# cancels it.

- **"What time is it?", on 8463 (T-I-M-E).** The time the way children learn
  it — "ten past six", "quarter to seven" — and, when bedtime's under two
  hours away, how long till then. Both on the menu's Timer & clock page,
  which lists the timers running, with Cancel.

- **Pause a phone** for homework or tidy-up time — 30 minutes, an hour, two,
  or until the morning — from the top of its page. Paused, it doesn't ring
  or call out and the fun lines are off; 999 and its messages still work.
  It turns itself back on, or Resume now.

- **This week**: each child's phone's week on the line — calls made and
  answered, talk time, missed calls and messages, who they talked to most,
  their busiest day, games played, and numbers they tried that aren't
  allowed — with the weeks before a click away. In the sidebar, and on the
  dashboard.

- **Keepsakes.** Press the star on a voicemail to keep it for good: a copy
  that stays whatever happens to the message — deleted on the phone, or
  cleared by retention. Give each a name, play it back, and download a
  year's as a zip, with what each one says. Kept copies are in backups.

- **Listening to a room**, like a baby monitor. A grown-up's phone rings a
  child's desk phone, which answers by itself on speaker, one way — from
  the phone page, or by dialling 88 and its extension. It beeps as it picks
  up and shows "Listening in" on its screen, never breaks into a call, and
  every listen is noted on the phone's page. Off unless switched on for
  both phones, and it takes the permission to listen in.

- **A printable contact sheet** for the fridge, or beside a phone with no
  screen: everyone a child can call, with their photo and what to dial —
  speed dials first — then the twocans lines to dial, each one ticked on or
  off: messages, the joke line, games, times tables, the radio, the time, the
  kitchen timer, silly voices, the walkie-talkie (on a paired phone's sheet),
  the Christmas countdown and the emergency number. With lots ticked, the
  picture shrinks so it still fits one page. Dinosaur, princess, racing-car or
  plain, on A4 or A5.
  Printed for one phone, it also says which key rings whom. From the
  Contacts screen, or a phone's page.

- **An update notice.** The System page says which version is running and
  whether a newer one is out — with how to update and what's new — and the
  menu's System entry shows "new". twocans asks GitHub twice a day; the
  System page can turn that off.

### Changed

- **A phone's Setup tab**: its numbers to dial — a long list now — sit side
  by side below the rest, not stretched down one column. And a MAC address
  box takes it however it's typed or pasted, and shows it as 00:0B:82:C1:23:45.

- **Uploads show how they're going.** A bar while a file goes up, then a
  wheel while the audio's prepared, and the form can't be pressed twice
  meanwhile; leaving the page mid-upload asks first. Every upload now saves
  without a full page load — jokes, greetings, the bedtime message and
  restoring a backup included.

- **Easier to read.** An accessibility pass with axe: text, links, teal
  buttons, chips and badges now meet WCAG AA contrast (4.5:1) — the greys and
  teal a shade deeper, the same warm look. Coral buttons keep their coral
  with dark lettering instead of white. The backup file picker has a label,
  and a Record button says when it's recording.
- Deleting a voicemail goes through Asterisk, like moving one, so the
  mailbox's numbering and the phone's message light stay right.

### Fixed

- A Yealink on newer firmware (a W60B on 77.85, for one) never got its
  settings: it asks for a boot file first (<mac>.boot), which twocans didn't
  serve, and was handed the sign-in page instead. twocans now serves boot
  files, and anything else asked of a provisioning address gets "not found"
  rather than the sign-in page.

- An HT802's second socket now gets # as its "dial now" key and the same
  wait after the last number as the first; only socket 1 was ever sent them.

- **A desk phone's voicemail button** now plays its messages (it dials 700).
  It did nothing before, because the phone was never told the number.

- **Staying signed in.** The web app signed a grown-up out after 24 minutes
  without a page load, whenever the browser closed, and every time the web
  container restarted or updated. Now it's a month from the last visit, and
  sign-ins outlive restarts and updates (they're kept in storage/sessions).

## [0.1.6] - 2026-09-30

Voicemail that goes where it should, announcements on a timetable, a choice of
which number calls go out from, and recording straight into the browser.

### Added

- **Each number's messages go to the right mailbox.** A number that rings one
  phone takes messages in that phone's mailbox — so the child hears them by
  dialling 700 — and any other number in the house's. Either can be changed
  on the Phone line screen ("messages go to").
- **Move a message to another mailbox** from the Voicemail screen: out of the
  house's into a phone's, say. It keeps its transcript and arrives unheard, so
  the phone's message light comes on. Each message says which mailbox it's in.
- **Rings before voicemail, for each phone** — 2 to 10 rings, on the phone's
  Rules tab. A call ringing several phones goes to voicemail once the longest
  of them stops. Five rings (30 seconds), as before, until it's set.
- **A speed dial for messages** — say 1 — as well as 700, set on the
  Voicemail screen. It's checked against every other number a child dials,
  shows in each phone's numbers, and can go on a hotkey.
- **Announcements that play by themselves** at a set time on chosen days —
  "bath time in ten minutes" at 18:50 on school nights. Set under the
  announcement's "Play it by itself".
- **Choose which number calls go out from.** A line with more than one
  number has a "Calls out from" card: the line's number for every phone, and
  each phone's own. By itself a phone calls out from the number pointed at
  it, if it has one, else the line's; the phone's page says which.
- **Changes for a phone that's off wait for it.** Saving hotkeys (or anything
  else a Grandstream is sent) while it's offline says so, and it's sent them
  the moment it's back; its Setup tab shows "Changes waiting" until then.
- **Record in the browser.** Every audio upload — announcements, greetings,
  a phone's message, a person's name clip, jokes, hold music — has a Record
  button beside "Choose a file". It needs twocans' secure (https) address.

### Changed

- **Development runs the real stack.** `make dev` is `compose.yaml` with
  `compose.dev.yml` on top: the web image built here, `./backend` mounted
  live. The old `docker-compose-local.yml` (and the separate dev PHP and nginx
  images) are gone — it had drifted, and under its own project name would have
  started an empty database. `./install.sh` and `./twocans` recognise a
  development setup and keep it one.

### Fixed

- **Short voicemails are kept.** Asterisk threw away any message under 4
  seconds of speech — "hi, it's Tom, call me back" is about 3. The minimum is
  now 1 second; a caller who says nothing still leaves nothing, as the silence
  is trimmed off before it's measured.
- **The call log** leaves out announcements and test calls (the Test call
  button, and dialling 600 or 601), and doesn't count them in its totals. A
  short number nobody answers to reads "Dialled 123 · not a phone number".
- **"Numbers the kids tried"** no longer lists extensions, twocans' own
  numbers or short numbers like a blocked speed dial: only numbers a grown-up
  could add. They're still blocked, and still in the call log.
- **Phone-width fixes:** the Phone line screen no longer runs off the right
  edge; a phone's header gives its name the row, with its status and Test call
  underneath; number pickers show whole numbers; long buttons wrap.
- `./twocans status` counts only the family's calls, like the call log.

## [0.1.5] - 2026-09-30

Grandstream desk phones, properly: all four models, hotkeys that work,
printable faceplates, changes sent straight to the phone, and announcements
that play through the speaker instead of as a call.

### Added

- **More desk phones.** The GHP610 and GHP611 (three hotkeys) and the GHP620
  (the white GHP621) alongside the GHP621. They share the GHP621's settings,
  so should work the same — but only the GHP621 has been tried on a real phone.
- **Adding a phone goes by what it is** — an app, a desk phone or an adapter,
  then the model, each drawn as it looks. **Look for phones** scans your
  network for Grandstreams that aren't added yet and knows each one's model;
  pick one and its MAC is filled in. One that asks twocans for its settings
  before it's added is offered too.
- **A phone's page is in tabs:** Rules, Keys & faceplate (desk phones), Setup.
  A phone that hasn't signed in yet opens on Setup.
- **Changes reach a Grandstream straight away.** Saving hotkeys, renaming it,
  or changing the name of someone on one of its keys sends it its settings —
  no restart. Its Setup tab can send them again, or restart it, and says when
  it last fetched them.
- **Hotkeys, laid out like the phone,** with a preview of its face, for a
  person, a group (by its speed dial) or a twocans number. A key follows a
  person or group when their number or speed dial changes. After you save
  someone who isn't on a key, twocans offers a free one; the phones list shows
  each desk phone's keys.
- **Printable faceplates.** A GHP62x's is a card for behind its clear cover; a
  GHP61x's a 35 × 8.5 mm label for above its three keys. Each shows the photo
  and name of whoever a key rings, in twocans colours or plain white, and is
  previewed beside the hotkeys as they're picked.
- **Announcements play through a desk phone's speaker,** with no call to
  answer and no microphone open — the way the phones page natively. A new
  `pager` service sends them as multicast on the house network (it opens no
  ports). A desk phone that has fetched its settings at home gets them this
  way; phone apps, and desk phones away from home, still get a call.
  `./twocans status` and System show whether the pager is running.
- **Your own hold music:** add songs under Greetings → Someone put on hold;
  whoever a phone puts on hold hears them, one after another.
- **A proper welcome for test calls** — "Congratulations! Your new phone is
  now on the twocans system…" — instead of Asterisk's stock demo, until a
  household records its own greeting. Built-in sounds live in
  `storage/defaults/`.
- **A recording that's the same as another's** (another announcement, or
  another phone's message) is saved, but says so: it's usually the wrong file
  picked.

### Changed

- **Saving doesn't reload the page.** Announcements, a phone's calls, hours,
  limits, message, hotkeys and provisioning, and the contact editor, save in
  place and say so, refreshing just what changed.
- **Getting started leaves the menu** once every step is done, and its page
  can hide it early. System brings it back.

### Fixed

- **A GHP621's hotkeys reach the phone.** They were written to settings the
  phone doesn't have, so it never saw them.
- **Music on hold.** A phone putting someone on hold left them in silence.
  The installer now fetches Asterisk's Opsound set (credited in NOTICE), and
  `./twocans update` adds it to an existing install.

## [0.1.4] - 2026-09-30

### Changed

- The README leads with the one-line install, and shows the installer and the
  app — screenshots from a demo install with a made-up family.

### Added

- **Images for ARM (a Raspberry Pi)** as well as Intel/AMD: twocans' own web and
  speech-to-text images are built natively for both and published under one
  name, so Docker pulls the one that fits. A Pi no longer spends 15–30 minutes
  building them; the installer checks for an ARM build and only builds locally
  when a release lacks one.
- **Releases build themselves**: pushing a version tag runs a GitHub Actions
  workflow that builds, publishes and checks the images on both chip types (and
  refuses a tag that doesn't match `backend/VERSION`). It can also be run by
  hand for an existing tag.
- **Checks on every push and pull request**: the scripts parse, and the whole
  test suite runs in the web image against a fresh MariaDB.

### Fixed

- **Grandstream phones and adapters fetched their settings and ignored them.**
  The file was written as numbered settings (`<P271>`…) but labelled as
  Grandstream's other, named format (`<config version="2">`), so a GHP621 or an
  HT801/HT802 never signed in. It's labelled version 1 now, and carries the MAC.
  The setup steps also say to leave `http://` out of the Config Server Path (the
  phone refuses it) and to fill in the Firmware Server Path when it's asked for.

- The install log used `sed -u` and `grep --line-buffered`, which BusyBox (as
  on Alpine) lacks, and stopped the installer after its first few lines there.
  It now uses `awk`, which behaves the same everywhere.

## [0.1.3] - 2026-09-28

### Added

- **Install in one line**: `curl -fsSL …/get.sh | bash` asks where to put
  twocans, installs git if it's missing (asking first), clones it and runs the
  installer; run again, it updates instead.
- **The installer offers to install Docker** when it's missing — with Docker's
  own script, asking first — adds you to the docker group and carries on in the
  same session. It also offers to start Docker when it's installed but stopped.
- **`http://twocans.local`**: the installer asks for a name on the network and
  publishes it through Avahi (one line in `/etc/avahi/hosts`, asking first; it
  offers to install Avahi where it's missing), then checks it answers. The name
  is in the final summary and `./twocans status`; uninstalling removes it.
- **Getting started**, in the web app: after the Owner is created, a checklist
  — add a phone, add the people they can call, connect a phone line (or skip
  it), make a test call — each ticked from what's really there. "Do it later"
  moves it to the menu, where it always is (with how far along it is); until
  then the dashboard carries a reminder. Households already running are marked
  finished (migration 047).
- **A record of every install**: the installer keeps what it showed — without
  the colours — in `storage/reports/install-<when>.log`, readable only by you,
  and `./twocans report` includes the latest.
- **Where Face ID needs HTTPS, it says so**: signing in with a passkey and
  adding twocans to a phone's home screen only work over HTTPS, so the
  installer's summary and Getting started point to where the address and
  certificate are set up.

### Fixed

- **The one-liner, and `./twocans update`, upgrade installs from 0.1.1 and
  earlier.** Those have no `./twocans`, and their Asterisk configs held
  passwords the old way, which blocked `git pull`; the configs are put back
  first (the installer now keeps the passwords elsewhere), then it updates.

- Running the installer as root set the app's user to root, which it can't run
  as: the web app wouldn't start once its image was built locally (as on a
  Raspberry Pi). The installer now uses the person behind sudo, or 1000, and
  repairs an `.env` that has 0; the image falls back to 1000 as well.

## [0.1.2] - 2026-09-28

### Added

- **A guided installer.** `./install.sh` checks the software (Docker, Compose,
  the tools it uses, memory and disk), asks a few questions with a suggested
  answer for each, checks every port twocans publishes — offering another port
  for the web interface or HTTPS when something else holds it, and explaining a
  clash on the SIP ports or call audio range, which can't move — and reads the firewall's rules (ufw or
  firewalld, with sudo, asking first) to check each port against them —
  allowed, allowed only from some addresses, or missing — offering to add only
  what's missing. It says when ufw is installed but switched off. On ARM (a Raspberry Pi) it builds the
  images locally. It is also the updater — `git pull && ./install.sh`, or
  `make update` — keeping `.env`, asking before cutting off a call in progress,
  and restarting Asterisk only when its transports or passwords changed.
  It also checks this machine: that its address won't change (warning when the
  router hands it out, with the hardware address to reserve it for), that Docker
  starts on boot, and that the clock is kept in time — offering to fix the last
  two. `--check`, `--yes`, `--reconfigure`, `--no-start`, `--write-secrets`, and
  `--uninstall`, which keeps your data unless you type `delete`, and only ever
  removes data — never the repo's own files.

- **Reset the Owner account from the command line**: `./install.sh
  --reset-owner` (or `make reset-owner`). It shows the Owner and offers a new
  sign-in email, a new password (clearing any lockout), removing its passkeys,
  and signing out every browser already signed in — confirming before it
  changes anything. With no Owner it makes one, from an existing grown-up or
  new. Signing out everywhere is new too: sessions begun before it are refused
  (migration 046). Standard installs get it with the next image.

- **`./twocans`, one command for looking after it**, working the same on a
  standard install and a development setup: `status` (containers, system
  checks, phones, the phone line and credit, today, backups, disk), `logs`,
  `backup` / `backups` / `restore`, `export` (the call log, voicemails and
  contacts as spreadsheets plus the recordings, in one zip), `version` and
  `update` (comparing with GitHub's latest; stopping rather than overwriting
  files changed by hand), `report` (a support report with passwords, names,
  numbers, emails, the domain and public IPs removed), plus `reset-owner`,
  `check` and `uninstall`. The installer and it share one look
  (`scripts/ui.sh`).
- The app knows its version: `backend/VERSION`, baked into the image.

### Changed

- **Asterisk's control passwords are no longer kept in tracked files.**
  `ari.conf` and `manager.conf` include them from `docker/asterisk/etc/secrets/`,
  which `install.sh` writes from `.env` and git ignores — so an update never
  conflicts with them, and they can't be committed. **Upgrading:** if `git pull`
  refuses because of local changes to those two files, run
  `git checkout -- docker/asterisk/etc/ari.conf docker/asterisk/etc/manager.conf`
  and pull again; then run `./install.sh` (or `./install.sh --write-secrets`)
  **before Asterisk next restarts**.

### Fixed

- Backups were readable by everyone on the machine; they hold the whole
  database, so they're now readable only by their owner (as exports and
  reports are).
- **Asterisk's spoken prompts were lost whenever the stack was taken down**, and
  group calls then admitted nobody: the image keeps them on an unnamed volume of
  its own. They now have a named volume, `asterisk-sounds`. Run `./install.sh`
  once after updating to fetch them into it.
- The README's copy of the example compose file was missing the phone line's
  SIP port.

## [0.1.1] - 2026-09-28

### Fixed

- **Group calls were never transcribed.** Their recording was set to be named
  after the call, but the conference's own settings profile overrode it, so
  Asterisk used its default name and the call log never found the file. The
  call now joins with a profile of its own built on the house one, so the
  name holds. Group calls recorded under the old name are matched up.
- **Group calls from a phone in adult mode were recorded**, for the same
  reason. They no longer are.
- The call log showed **Transcribing…** for ever on calls that were never
  recorded: adult mode, announcements, or a recording that never appeared.
  Fifteen minutes after such a call ends it now says nothing was recorded.

## [0.1.0] - 2026-09-28

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
- **Who a group call reached**: the call log and dashboard say "Nana joined ·
  Grandad didn't answer" for group calls made from now on. Migration 036.
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

[Unreleased]: https://github.com/tombruton87/TwoCans/compare/v0.1.7...HEAD
[0.1.7]: https://github.com/tombruton87/TwoCans/compare/v0.1.6...v0.1.7
[0.1.6]: https://github.com/tombruton87/TwoCans/compare/v0.1.5...v0.1.6
[0.1.5]: https://github.com/tombruton87/TwoCans/compare/v0.1.4...v0.1.5
[0.1.4]: https://github.com/tombruton87/TwoCans/compare/v0.1.3...v0.1.4
[0.1.3]: https://github.com/tombruton87/TwoCans/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/tombruton87/TwoCans/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/tombruton87/TwoCans/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/tombruton87/TwoCans/releases/tag/v0.1.0
