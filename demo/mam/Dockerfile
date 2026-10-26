# MAM application image for the demo: PHP-FPM + Nginx + queue worker + scheduler in one container.
# Build context is the repository root (see demo/docker-compose.yml).

ARG PHP_VERSION=8.4

# --- PHP runtime with the media tools ------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-trixie AS runtime

# Office conversion (LibreOffice) adds ~500 MB; build with --build-arg WITH_OFFICE=0 to leave it out.
ARG WITH_OFFICE=1
ENV DEBIAN_FRONTEND=noninteractive

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

# HTTPS mirrors: some networks block plain-HTTP package downloads.
RUN sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list.d/debian.sources \
 && apt-get update \
 && apt-get install -y --no-install-recommends \
      nginx supervisor socat ffmpeg poppler-utils imagemagick ghostscript unzip mariadb-client \
 && if [ "$WITH_OFFICE" = "1" ]; then \
      apt-get install -y --no-install-recommends \
        libreoffice-writer-nogui libreoffice-calc-nogui libreoffice-impress-nogui fonts-dejavu-core; \
    fi \
 && install-php-extensions pdo_mysql gd intl zip bcmath pcntl sockets opcache \
 && rm -rf /var/lib/apt/lists/*

COPY demo/mam/php.ini /usr/local/etc/php/conf.d/zz-mam.ini
COPY demo/mam/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-mam.conf
COPY demo/mam/nginx.conf /etc/nginx/sites-available/default
RUN touch /etc/nginx/mam-canonical-host.conf
COPY demo/mam/supervisord.conf /etc/supervisor/conf.d/mam.conf

# --- PHP dependencies ------------------------------------------------------------------------------
FROM runtime AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

# --- Front end -------------------------------------------------------------------------------------
FROM node:22-slim AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY --from=vendor /app /app
RUN npm run build

# --- Final image -----------------------------------------------------------------------------------
FROM runtime
WORKDIR /var/www/mam
COPY --from=vendor --chown=www-data:www-data /app ./
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
COPY --from=assets --chown=www-data:www-data /app/public/vendor ./public/vendor
COPY demo/mam/entrypoint.sh /usr/local/bin/mam-entrypoint
RUN chmod +x /usr/local/bin/mam-entrypoint \
 && rm -f .env && mkdir -p storage/app storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8000
ENTRYPOINT ["mam-entrypoint"]
CMD ["supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
