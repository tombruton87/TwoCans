# twocans on a Synology

twocans comes as a Synology package: you install it in Package Center, a
wizard asks a few questions, and it runs in Container Manager. It then has a
window of its own in DSM — how it's doing, its calls and phones, its settings —
in English, German, French, Spanish, Dutch, Italian, Portuguese or Polish.

It has run on one Synology so far (DSM 7.3.2). If yours does something this
doesn't describe, please [open an issue](https://github.com/tombruton87/TwoCans/issues)
— the support report it makes (see [When something goes wrong](#when-something-goes-wrong))
is the most useful thing to attach.

## What you need

- **DSM 7.2.1 or later.** Check in Control Panel → Info Center.
- **A Synology that runs Container Manager.** If Package Center lists
  Container Manager for your model, it does; most 64-bit models from the last
  few years do. 32-bit models (the "j" ones, mostly) can't run twocans.
- **About 4 GB of space** on a volume for twocans' images, and more for
  recordings as they build up.
- **Memory:** 2 GB will do; 4 GB or more is comfortable. Speech-to-text is the
  hungry part, and can be switched off.
- **An address that doesn't change.** Phones find twocans at the Synology's
  address, so if your router hands it out, reserve it there (a DHCP
  reservation) — or give the Synology a fixed one in Control Panel → Network.

## Before you install

1. **Install Container Manager**, from Package Center, if it isn't already.
2. **Let Package Center install twocans' package.** twocans isn't signed by
   Synology, so DSM asks before installing it. If it refuses outright, go to
   Package Center → Settings → General → Trust Level, and choose
   *Any publisher*. (You can set it back afterwards.)

## Install

1. Download `twocans-<version>.spk` from the
   [latest release](https://github.com/tombruton87/TwoCans/releases/latest)
   — it's under *Assets*.
2. In Package Center, choose **Manual Install**, pick the file, and go on.
3. The wizard asks:
   - **This Synology's address** — filled in for you. Phones use it to find
     twocans.
   - **Ports** — the wizard looks at what's already in use on your Synology
     and suggests free ones: the web app (8083 unless taken), HTTPS (8443 —
     DSM keeps 443 for itself), the phones' SIP port (5060), your phone line's
     (5062), and call audio (10000–10100, or another range if something holds
     part of it — a UniFi controller, say). Keep the suggestions unless you
     know you need others.
   - **Time zone** and **country calling code** — filled in from DSM's.
   - **Speech-to-text** — *base* is quicker and lighter; *small* copes better
     with noisy lines, at about three times the work. On a Synology with
     little memory, choose *base*.
4. Agree, and Package Center installs it.

The first start downloads about 3 GB of images, so give it a while — ten
minutes, or more on a slow connection. You can watch it in **Package Center →
twocans → View log**, or in twocans' own window (below), under Logs.

## Open twocans

When it's ready, **twocans** appears in DSM's main menu. Open it, and you get
twocans' window in DSM:

- **Overview** — how it's doing, each part of it, and a getting-started list
  that ticks itself off as you go.
- **Live calls**, **Phones**, **Activity**, **Phone line**, **Resources** —
  what they say.
- **Settings** and **Logs** — for DSM administrators.

**Open twocans** (top right) opens the app itself, in a tab of its own. The
first time, create your account there: you become twocans' Owner. Then add
your phones (in the app: *Phones → Add a phone*) and the people they can call
(*People*).

## Let twocans through the firewall

If DSM's firewall is on (Control Panel → Security → Firewall), it needs to let
twocans in. Edit its rules, add one, and under *Select from a list of built-in
applications* tick the twocans entries:

| Entry | For | From |
|---|---|---|
| twocans: phones (SIP) | phones registering | your home network |
| twocans: web app | the app | your home network |
| twocans: call audio (RTP) | the sound of every call | anywhere |
| twocans: phone line (SIP) | your phone line provider | anywhere |
| twocans: HTTPS | the app from outside the house | anywhere |

The entries carry the ports twocans actually uses, even if the wizard moved
them.

## Calls from outside the house

Phones inside the house need nothing more. For calls to and from a real phone
line, your **router** forwards two things to the Synology: the phone line's
SIP port (UDP) and the call audio range (UDP) — and HTTPS (TCP), if you want
the app from outside. twocans' window lists exactly which, under **Phone line
→ Your router**.

## HTTPS, with DSM's own certificate

Face ID sign-in, and adding twocans to a phone's home screen, need HTTPS with a
proper certificate. DSM probably has one already — the Let's Encrypt one it
keeps for your Synology's DDNS name (`yourname.synology.me`), say. If not, make
one in Control Panel → Security → Certificate → Add.

Then in twocans' window: **Settings → HTTPS**, choose the certificate (or
*Whichever DSM uses as its default*), the name to use, and **Use this
certificate**. twocans serves it, picks up DSM's renewals by itself, and — if
you leave the box ticked — makes `https://<that name>:<HTTPS port>` its
address. From outside the house, your router forwards the HTTPS port (see
above).

## Your data

Recordings, voicemails, photos, backups and twocans' settings live in a new
shared folder, **twocans**, on your volume. DSM gives it only to the package
at first: to open it in File Station, give yourself access in **Control Panel
→ Shared Folder → twocans → Edit → Permissions**.

That shared folder is also the one to back up — **Hyper Backup** can copy it
off the Synology. twocans makes its own backups too (in the app: *Backups*),
which land in the same folder.

To take everything with you, **Settings → Your data → Export everything** in
twocans' window makes one zip: the call log, contacts and voicemails as
spreadsheets, and the recordings as audio.

## Let others look

Other DSM users can see twocans' window — how it's doing, its calls and its
numbers — without changing anything, or hearing recordings. Name them in
**Settings → Who else can look**, and allow them twocans in **Control Panel →
Application Privileges**.

## Looking after itself

- **The watchdog** starts any part of twocans that's stopped working for a
  minute and a half — up to three times an hour, after which it leaves it for
  you to look at. Switch it off in **Settings → Looking after twocans**.
- **DSM notifications** tell you when a part stopped, setup didn't finish, the
  phone line stopped registering, a phone has been offline for a day, the
  HTTPS certificate is running out, or a new release is out. Switch them off
  there too.

DSM's notification tool only runs as root, which a twocans package isn't, so
twocans runs it — and only it — through a short-lived container, using the
Docker access Container Manager gives it.

## Updating

When a new release is out, twocans' window says so on its Overview. Download
the new `.spk` from the release, and install it over the old one in **Package
Center → Manual Install**. Your settings, calls and recordings carry over.

**Update the app**, on the Overview, fetches newer images for twocans' app
when the package is ahead of them.

## When something goes wrong

- **twocans' window → Logs** has the setup log and each part's own. If setup
  stopped, it says why, and what to do — then start twocans again in Package
  Center (twocans → Run).
- **Logs → Make a support report** gathers versions, checks and every part's
  log, with names, numbers, emails and passwords taken out. Attach it to an
  issue.
- **Package Center → twocans → View log** has the same setup log, if the
  window won't open.

Some things the log may say:

| It says | What to do |
|---|---|
| A port is already in use | Something else on the Synology has it. The wizard suggests free ones on a fresh install; or free it, and press Run. |
| Call audio needs 10000–10100, and one is in use | twocans moves call audio to a free range by itself; your router then forwards that range instead. |
| Speech-to-text runs uncapped | Normal on a Synology: its kernel can't cap a container's processor use. |
| The app is running an older version than the package | Press **Update the app**. If it still says so, the newer images haven't been published yet. |
| The Owner is locked out | **Settings → Locked out of twocans?** gives the Owner a new password. |

## Uninstalling

In Package Center: twocans → Uninstall. twocans stops and its containers go,
but **your data stays** — the twocans shared folder, and the call history in
Container Manager's volumes — so installing again picks up where you left off.

To remove everything for good, also delete the **twocans** shared folder
(Control Panel → Shared Folder) and the volumes whose names start
**twocans_** (Container Manager → Volume).

## Without the package: over SSH

The one-line installer works on a Synology too. Install **Container Manager**
and **Git Server** from Package Center, turn on SSH (Control Panel → Terminal &
SNMP), sign in as an administrator, and run:

```bash
curl -fsSL https://raw.githubusercontent.com/tombruton87/TwoCans/main/get.sh | sudo bash
```

It puts twocans on a volume (`/volume1/docker/twocans`), asks the same
questions at the terminal, and says which ports to open in the firewall. You
then look after it with `sudo ./twocans` in that folder. The package and this
are two different installs of the same thing — use one or the other.
