# =============================================================================
# Health Tracker — PHP 8.5 FPM application image
# =============================================================================
# Runs as a NON-ROOT user from day one (uid/gid match the host `health-tracker`
# system user so bind-mounted files keep sane ownership and artisan never has to
# be run as root on the host — that is what poisoned Scribly's cache files).
# =============================================================================
FROM php:8.5-fpm-alpine

ARG APP_UID=990
ARG APP_GID=983
ARG APP_USER=app

# ---- PHP extensions --------------------------------------------------------
# pdo_pgsql : Postgres 18
# redis     : queue/cache driver — step 2 puts the ingest parser on it
# pcntl     : HARD requirement of laravel/horizon. Horizon's master process
# posix       forks and signals its supervisors; composer refuses to install it
#             without both (`ext-pcntl * is missing from your platform`), and
#             pcntl is not in the stock php:8.5-fpm-alpine build.
# gd        : step 5's photo path. The server re-encodes every uploaded meal
#             photo to JPEG — that is what ENFORCES the ~1024 px long edge and
#             what strips EXIF (including GPS) rather than trusting the browser
#             to have done it — and generates the 256 px thumbnail that survives
#             the 90-day prune of the original.
#             `--with-jpeg` is not optional: without it the extension builds
#             fine and `imagecreatefromjpeg()` simply does not exist, which is a
#             runtime failure on the first photo rather than a build failure.
# zip       : `php artisan fitage:import`. An .xlsx IS a zip container, and the
#             Fitage scale exports body-composition history as one. NOT in the
#             stock php:8.5-fpm-alpine build — verified by its absence from
#             `php -m` on the running image, which is how this was found.
#             The alternative was ~150 lines of hand-rolled central-directory
#             and DEFLATE parsing (a reader here, plus a writer in the test
#             fixture) to avoid one system library. libzip is ~1 MB, the
#             extension is maintained by people who do this for a living, and
#             this project has already taken exactly this trade once: step 5
#             added gd the same way.
# NB: opcache is compiled into the official php:8.5 images already — running
#     `docker-php-ext-install opcache` against them fails, so it is not listed.
RUN set -eux; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers postgresql-dev icu-dev \
        libjpeg-turbo-dev libpng-dev libzip-dev; \
    apk add --no-cache postgresql-client icu-libs libpq bash libjpeg-turbo libpng libzip; \
    docker-php-ext-configure gd --with-jpeg; \
    docker-php-ext-install pdo_pgsql bcmath intl pcntl posix gd zip; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apk del .build-deps; \
    rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Composer prefers an `unzip` binary when it finds one, and an unzip binary —
# Alpine's BusyBox applet and Info-ZIP alike — restores the mode STORED in the
# archive without applying umask; composer's dist zips store 0777 on directory
# entries, so `composer install` left ~1,900 world-writable package dirs under
# vendor/ (server audit C1, 2026-08-21; 1944 → 0 once unzip was unreachable).
# Installing a "real" unzip does not help: Info-ZIP 6.00 yields 0777 dirs AND
# 0666 files. Nothing here calls the binary (the .xlsx importer uses ext-zip),
# so the applet name goes and composer falls back to PHP's ZipArchive, which
# creates entries as 0777 & ~umask → 755/644. Composer then prints a warning
# that archive permissions are lost — harmless: it re-chmods declared binaries
# itself. The layer asserts its own postcondition so a moved path fails loudly.
RUN rm -f /usr/bin/unzip && ! command -v unzip

# ---- Non-root app user -----------------------------------------------------
RUN set -eux; \
    addgroup -g "${APP_GID}" "${APP_USER}"; \
    adduser -D -u "${APP_UID}" -G "${APP_USER}" -h /home/"${APP_USER}" "${APP_USER}"; \
    mkdir -p /home/"${APP_USER}"/.composer; \
    chown -R "${APP_UID}:${APP_GID}" /home/"${APP_USER}"

# php-fpm's stock pool wants to setuid to www-data. The container already runs
# as a non-root user, so those directives are neutered instead of ignored-with-
# a-warning on every boot.
RUN set -eux; \
    sed -ri 's/^user = www-data/;user = www-data/; s/^group = www-data/;group = www-data/' \
        /usr/local/etc/php-fpm.d/www.conf

COPY php.ini      /usr/local/etc/php/conf.d/99-health-tracker.ini
COPY zz-pool.conf /usr/local/etc/php-fpm.d/zz-health-tracker.conf

ENV COMPOSER_HOME=/home/app/.composer
ENV COMPOSER_ALLOW_SUPERUSER=0

WORKDIR /var/www/html

USER ${APP_USER}

EXPOSE 9000
CMD ["php-fpm"]
