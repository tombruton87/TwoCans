# twocans for Synology

A Synology package (`.spk`) for DSM 7.2.1 or later, on a model that runs
Container Manager. It has run on one Synology so far (DSM 7.3.2) — reports welcome.

## Building it

```bash
synology/build.sh        # dist/twocans-<version>-1.spk
synology/build.sh 2      # the same release, rebuilt: -2
```

Install it in **Package Center → Manual Install**. It isn't signed by Synology,
so DSM warns first; if it refuses, allow it under **Package Center → Settings →
Trust Level → Any publisher**.

## How it works

DSM 7 runs a third-party package as a user of its own, never root, and only
root can use Docker. So the package does no Docker work itself:

- **The wizard** (`WIZARD_UIFILES/install_uifile.sh`) asks what `./install.sh`
  would — the address, time zone, calling code and speech-to-text model — with
  this Synology's own as suggestions. `scripts/postinst` writes the answers to
  the package's own folder.
- **Container Manager** runs one project for the package (`conf/resource` →
  `setup/compose.yaml`): a setup container, with Docker's socket.
- **The setup container** (`setup/run.sh`) puts this version's files in the
  `twocans` shared folder and runs `./install.sh --yes` there with the
  wizard's answers — after that, twocans is an ordinary install in that
  folder, its own compose project. On later starts it just starts twocans;
  when the package stops, it takes twocans down. A new version is set up
  again the first time it starts.
- **The firewall** gets twocans' ports as named entries (`target/twocans.sc`),
  and **the main menu** an icon that opens the web app (`target/ui/config`).

The wizard looks at which ports are already taken on the Synology, suggests
free ones for the web app, HTTPS and the two SIP ports, and won't take one in
use; call audio's 10000–10100 can't move, so it warns if any is taken. DSM
writes the firewall entries and the menu icon's address before the wizard
runs, so the setup container brings both into line with the ports twocans
ended up on, each time it starts.

## When something goes wrong

**Package Center → twocans → View log** shows the setup container's progress,
the installer's own output included; the same log is `package.log` in the
`twocans` shared folder, with the installer's own logs in `storage/reports`.
DSM gives that folder only to the package's own user at first: to open it in
File Station, give yourself access in **Control Panel → Shared Folder → twocans
→ Edit → Permissions**. Container Manager's own log for `twocans-setup`
(Container → twocans-setup → Log) has the same lines. Container Manager shows the containers themselves.
Over SSH, the folder is an ordinary install: `cd /volume1/twocans &&
sudo ./twocans status`.
