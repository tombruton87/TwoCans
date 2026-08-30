# TwoCans

A parents-only web admin for a self-hosted kids' phone line. Kids use an ordinary
handset plugged into a Grandstream ATA; you control **who** can call, **when**,
and review **what happened** — every call recorded and transcribed on your own
server.

> Full documentation lives in the [`docs/`](docs/) folder (a hosted site is on its way).

## Status

The phone line works. Guardians, phones, contacts, call log, recordings,
transcription, voicemail and live listen-in are real, in MariaDB and Asterisk.

## Install

You need **Docker Engine** and **Docker Compose v2**, and a machine your phones can reach.

### Easiest — one command

```bash
git clone https://github.com/tombruton87/TwoCans.git && cd TwoCans
./install.sh
```

`install.sh` checks the prerequisites, works out your LAN address, writes `.env`
with fresh secrets, pulls the images and starts everything (it also installs
Asterisk's sound prompts). Then open the URL it prints and complete first-run setup.

### Or by hand — copy the example compose

The repo ships a ready-to-run compose file with the example values **inlined**, so
there is no `.env` to write by hand:

```bash
git clone https://github.com/tombruton87/TwoCans.git && cd TwoCans
cp compose.example.yml compose.yml     # then edit the values below
docker compose up -d
```

Edit `compose.yml` before you start:

- **`SIP_DOMAIN`** and **`APP_URL`** — your LAN IP (phones register to it; not `localhost`)
- **`DB_PASSWORD`**, **`DB_ROOT_PASSWORD`**, **`ARI_PASSWORD`**, **`AMI_PASSWORD`** — replace the `change-me`s
- **`APP_KEY`** — a 64-hex-char key: `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`
- **`TZ`** — your timezone

Then open `http://<your-ip>:8083` and create the household Owner.

`docker compose up` pulls the images from Docker Hub (`hamletdigital/...`); the app
is baked into `twocans-web`, so nothing is built. The optional `cloudflared` and
`adminer` services are behind `--profile tunnel` / `--profile tools`.

### The full example compose

```yaml
# TwoCans example compose. Edit the values, then: docker compose up -d
# (copy with: cp compose.example.yml compose.yml)

services:
  asterisk:
    image: andrius/asterisk:22
    container_name: twocans-asterisk
    restart: unless-stopped
    ports:
      - "5060:5060/udp"
      - "5060:5060/tcp"
      - "10000-10100:10000-10100/udp"
    volumes:
      - ./docker/asterisk/etc:/etc/asterisk
      - ./docker/asterisk/cdr:/var/log/asterisk/cdr-csv
      - ./docker/asterisk/recordings:/var/spool/asterisk/monitor
      - ./docker/asterisk/voicemail:/var/spool/asterisk/voicemail
      - ./docker/asterisk/asks:/var/spool/asterisk/asks
      - ./storage:/var/lib/twocans:ro
      - asterisk-lib:/var/lib/asterisk
      - asterisk-spool:/var/spool/asterisk
      - asterisk-log:/var/log/asterisk
    environment:
      TZ: Europe/London
    networks: [twocans]
    healthcheck:
      test: ["CMD", "asterisk", "-rx", "core show version"]
      interval: 10s
      timeout: 5s
      retries: 3

  mariadb:
    image: mariadb:11.4
    container_name: twocans-mariadb
    restart: unless-stopped
    environment:
      MARIADB_ROOT_PASSWORD: change-me
      MARIADB_DATABASE: twocans
      MARIADB_USER: twocans
      MARIADB_PASSWORD: change-me
      TZ: Europe/London
    volumes:
      - mariadb-data:/var/lib/mysql
      - ./docker/mariadb/log:/var/log/mysql
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 10s
      timeout: 5s
      retries: 5
    networks: [twocans]

  web:
    image: docker.io/hamletdigital/twocans-web:latest
    container_name: twocans-web
    restart: unless-stopped
    depends_on:
      mariadb: {condition: service_healthy}
    ports:
      - "8083:80"
      - "443:443"
    environment:
      DB_HOST: mariadb
      DB_PORT: 3306
      DB_NAME: twocans
      DB_USER: twocans
      DB_PASSWORD: change-me
      ARI_BASE_URL: http://asterisk:8088
      ARI_USERNAME: twocans
      ARI_PASSWORD: change-me
      AMI_HOST: asterisk
      AMI_PORT: 5038
      AMI_USERNAME: twocans
      AMI_PASSWORD: change-me
      ASTERISK_GENERATED_DIR: /etc/asterisk/generated
      APP_KEY: 0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
      SIP_DOMAIN: 192.168.1.10
      APP_URL: http://192.168.1.10:8083
      DEFAULT_COUNTRY_CODE: 44
      CDR_CSV_PATH: /var/log/asterisk/cdr-csv/Master.csv
      RECORDINGS_PATH: /var/spool/asterisk/monitor
      VOICEMAIL_PATH: /var/spool/asterisk/voicemail
      ASK_RECORDINGS_PATH: /var/spool/asterisk/asks
      PHOTO_PATH: /var/lib/twocans/photos
      JOKES_PATH: /var/lib/twocans/jokes
      BACKUPS_PATH: /var/lib/twocans/backups
      WHISPER_URL: http://whisper:9000
      WHISPER_LANGUAGE: en
      TZ: Europe/London
    volumes:
      - ./docker/asterisk/etc:/etc/asterisk
      - ./docker/asterisk/cdr:/var/log/asterisk/cdr-csv:ro
      - ./docker/asterisk/recordings:/var/spool/asterisk/monitor
      - ./docker/asterisk/voicemail:/var/spool/asterisk/voicemail
      - ./docker/asterisk/asks:/var/spool/asterisk/asks
      - ./docker/php/log:/var/log/php-fpm
      - ./docker/nginx/log:/var/log/nginx
      - ./storage:/var/lib/twocans
      - ./docker/nginx/certs:/certs
      - ./docker/nginx/acme:/acme
      - ./docker/web/nginx.conf:/etc/nginx/http.d/default.conf:ro
    networks: [twocans]
    healthcheck:
      test: ["CMD", "wget", "--quiet", "--tries=1", "--spider", "http://127.0.0.1/"]
      interval: 10s
      timeout: 5s
      retries: 3

  transcriber:
    image: docker.io/hamletdigital/twocans-web:latest
    container_name: twocans-transcriber
    restart: unless-stopped
    depends_on:
      mariadb: {condition: service_healthy}
    entrypoint: ["php"]
    command: ["/var/www/html/bin/transcribe.php", "--watch"]
    environment:
      DB_HOST: mariadb
      DB_PORT: 3306
      DB_NAME: twocans
      DB_USER: twocans
      DB_PASSWORD: change-me
      RECORDINGS_PATH: /var/spool/asterisk/monitor
      VOICEMAIL_PATH: /var/spool/asterisk/voicemail
      ASK_RECORDINGS_PATH: /var/spool/asterisk/asks
      JOKES_PATH: /var/lib/twocans/jokes
      WHISPER_URL: http://whisper:9000
      WHISPER_LANGUAGE: en
      WHISPER_MODEL: base
      TZ: Europe/London
    volumes:
      - ./docker/asterisk/recordings:/var/spool/asterisk/monitor:ro
      - ./docker/asterisk/voicemail:/var/spool/asterisk/voicemail:ro
      - ./docker/asterisk/asks:/var/spool/asterisk/asks:ro
      - ./storage:/var/lib/twocans:ro
    networks: [twocans]

  whisper:
    image: docker.io/hamletdigital/twocans-whisper:latest
    container_name: twocans-whisper
    restart: unless-stopped
    environment:
      WHISPER_MODEL: base
      WHISPER_LANGUAGE: en
      WHISPER_COMPUTE_TYPE: int8
      WHISPER_THREADS: 4
      TZ: Europe/London
    volumes:
      - whisper-models:/data/whisper
    cpus: 4.0
    mem_limit: 2g
    networks: [twocans]

  cloudflared:
    image: cloudflare/cloudflared:latest
    container_name: twocans-cloudflared
    restart: unless-stopped
    profiles: ["tunnel"]
    command: ["tunnel", "--no-autoupdate", "run"]
    environment:
      TUNNEL_TOKEN: 
      TZ: Europe/London
    networks: [twocans]

  adminer:
    image: adminer:5
    container_name: twocans-adminer
    restart: unless-stopped
    profiles: [tools]
    depends_on: [mariadb]
    ports:
      - "8089:8080"
    environment:
      ADMINER_DEFAULT_SERVER: mariadb
    networks: [twocans]

volumes:
  mariadb-data:
  whisper-models:
  asterisk-lib:
  asterisk-spool:
  asterisk-log:

networks:
  twocans:
    driver: bridge
```

## What runs

| Service | Image | Purpose |
| --- | --- | --- |
| `web` | `hamletdigital/twocans-web` | the app — nginx + PHP-FPM in one; the app is baked in |
| `transcriber` | `hamletdigital/twocans-web` | worker that transcribes recordings |
| `whisper` | `hamletdigital/twocans-whisper` | speech-to-text, on this box |
| `asterisk` | `andrius/asterisk:22` | the PBX (SIP + RTP) |
| `mariadb` | `mariadb:11.4` | the database |

## License

**Business Source License 1.1** — free for household / non-commercial
self-hosting (see [`LICENSE`](LICENSE)); it converts to Apache-2.0 on its Change
Date. The "TwoCans" name and mark are trademarks of Hamlet Digital.

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md), [`SECURITY.md`](SECURITY.md) and
[`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md).

Built and published by Hamlet Digital. Issues, PRs and questions welcome.
