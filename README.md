# TwoCans

A parents-only web admin for a self-hosted kids' phone line. Kids use an ordinary
handset plugged into a Grandstream ATA; you control **who** can call, **when**,
and review **what happened** — every call recorded and transcribed on your own
server.

> Full documentation lives in the [`docs/`](docs/) folder (a hosted site is on its
> way). This README is just enough to get running.

## Status

The phone line works. Guardians, phones, contacts, call log, recordings,
transcription, voicemail and live listen-in are real, in MariaDB and Asterisk.

## Quick start

You need Docker Engine + Compose. Then either:

```bash
./install.sh                 # recommended: checks prereqs, writes .env, starts everything
```

or copy the ready-made example and run it:

```bash
cp compose.example.yml compose.yml   # then edit SIP_DOMAIN, APP_URL, passwords, APP_KEY
docker compose up -d
```

Open the printed URL and complete first-run setup to create the household Owner.

## What runs

| Service | Image | Purpose |
| --- | --- | --- |
| `web` | `hamletdigital/twocans-web` | the app — nginx + PHP-FPM in one; the app is baked in |
| `transcriber` | `hamletdigital/twocans-web` | worker that transcribes recordings |
| `whisper` | `hamletdigital/twocans-whisper` | speech-to-text, on this box |
| `asterisk` | `andrius/asterisk:22` | the PBX (SIP + RTP) |
| `mariadb` | `mariadb:11.4` | the database |

Ports, HTTPS, the security model and backup are all in the docs.

## License

**Business Source License 1.1** — free for household / non-commercial
self-hosting (see [`LICENSE`](LICENSE)); it converts to Apache-2.0 on its Change
Date. The "TwoCans" name and mark are trademarks of Hamlet Digital.

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md), [`SECURITY.md`](SECURITY.md) and
[`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md).

Built and published by Hamlet Digital. Issues, PRs and questions welcome.
