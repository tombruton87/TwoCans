FROM php:8.3-fpm-alpine

ARG UID=1000
ARG GID=1000

# PHP-FPM plus the runtime tools the app needs (same set as the standalone
# php-fpm image), and nginx so this one image is the whole web server.
# libqrencode-tools renders the provisioning QR code; gd strips EXIF/GPS from
# photos of children; ffmpeg converts uploaded jokes to 8kHz mono WAV; and
# mariadb-client is what the backup/restore scripts shell out to.
RUN apk add --no-cache tzdata libqrencode-tools libjpeg-turbo libpng libwebp ffmpeg mariadb-client nginx \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
      libjpeg-turbo-dev libpng-dev libwebp-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql opcache gd \
 && apk del .build-deps

# openssl for the default self-signed cert, certbot to replace it with a real one
# once the household asks, and supervisor to run FPM + nginx + the two loops.
RUN apk add --no-cache openssl certbot certbot-dns-cloudflare supervisor

# Run the pool as the host user so bind-mounted code stays editable on both sides.
RUN addgroup -g "${GID}" twocans 2>/dev/null || true \
 && adduser -u "${UID}" -G twocans -S -H -D twocans 2>/dev/null || true \
 && mkdir -p /var/log/php-fpm /var/log/nginx \
 && chown -R "${UID}:${GID}" /var/log/php-fpm

COPY docker/web/php.ini /usr/local/etc/php/conf.d/zz-twocans.ini
COPY docker/web/www.conf /usr/local/etc/php-fpm.d/zz-twocans.conf
COPY docker/web/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/web/supervisord.conf /etc/supervisord.conf
COPY docker/web/entrypoint.sh docker/web/loop-ddns.sh docker/web/loop-certbot.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/loop-ddns.sh /usr/local/bin/loop-certbot.sh

# The two-cans web app itself, baked into the image so the container is
# self-contained (build context is the repo root). Read-only at runtime; all
# app data lives in the mounted volumes.
COPY backend/ /var/www/html

WORKDIR /var/www/html

EXPOSE 80 443

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
