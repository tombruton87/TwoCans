FROM php:8.3-fpm-alpine

ARG UID=1000
ARG GID=1000

# pdo_mysql for MariaDB, opcache for performance. AMI needs no ext-sockets —
# core stream_socket_client() speaks to it fine.
# libqrencode-tools renders the provisioning QR code as SVG, so no PHP QR
# library has to be vendored in.
# gd re-encodes uploaded photos, which is how EXIF is stripped — a photo taken
# on a phone carries GPS coordinates, and these are pictures of children.
# ffmpeg converts uploaded jokes to the 8kHz mono WAV Asterisk plays. It is the
# only thing here that reads whatever a parent happens to have — a voice memo
# from a phone, an MP3, an Opus file — so it earns its ~50MB.
# mariadb-client provides mariadb-dump and mariadb, which the backup and
# restore scripts shell out to. No daemon — just the client tools.
RUN apk add --no-cache tzdata libqrencode-tools libjpeg-turbo libpng libwebp ffmpeg mariadb-client \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
      libjpeg-turbo-dev libpng-dev libwebp-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql opcache gd \
 && apk del .build-deps

# Run FPM as the host user so bind-mounted code stays editable on both sides.
RUN addgroup -g "${GID}" twocans 2>/dev/null || true \
 && adduser -u "${UID}" -G twocans -S -H -D twocans 2>/dev/null || true \
 && mkdir -p /var/log/php-fpm \
 && chown -R "${UID}:${GID}" /var/log/php-fpm

COPY php.ini /usr/local/etc/php/conf.d/zz-twocans.ini
COPY www.conf /usr/local/etc/php-fpm.d/zz-twocans.conf

# Starts the once-a-minute dynamic DNS check alongside FPM, then hands over to
# it. See entrypoint.sh for why that is not a second container.
COPY entrypoint.sh /usr/local/bin/twocans-entrypoint.sh
RUN chmod +x /usr/local/bin/twocans-entrypoint.sh

WORKDIR /var/www/html

EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/twocans-entrypoint.sh"]
CMD ["php-fpm"]
