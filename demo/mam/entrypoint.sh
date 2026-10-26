#!/bin/bash
# MAM container for the demo: waits for the credentials Koha publishes, writes .env, migrates, starts.
set -euo pipefail
cd /var/www/mam
log() { echo "[mam] $*"; }

log "Waiting for Koha to publish the MAM credentials..."
until [ -s "$MAM_SHARED_ENV" ]; do sleep 2; done

log "Waiting for the database at $DB_HOST..."
until mariadb -h "$DB_HOST" -u "$DB_USERNAME" -p"$DB_PASSWORD" -e 'SELECT 1' "$DB_DATABASE" >/dev/null 2>&1; do sleep 2; done

# The application key encrypts sessions and stored settings: create it once and keep it with the data.
KEY_FILE=storage/app/.demo-app-key
[ -s "$KEY_FILE" ] || echo "base64:$(head -c 32 /dev/urandom | base64)" > "$KEY_FILE"

# Settings from docker-compose.yml are real environment variables; only generated values go in .env.
{
    echo "APP_KEY=$(cat "$KEY_FILE")"
    cat "$MAM_SHARED_ENV"
} > .env
chown www-data:www-data .env "$KEY_FILE"
chmod 640 .env "$KEY_FILE"
mkdir -p storage/app/dam storage/app/dam-work storage/framework/{cache,sessions,views} storage/logs
chown -R www-data:www-data storage bootstrap/cache

# Sessions belong to one host name: send visitors who use another name (localhost:8000) to APP_URL.
app_host_re=$(printf '%s' "${APP_URL#*://}" | sed 's/[.]/[.]/g')
printf 'if ($http_host !~* "^%s$") { return 302 %s$request_uri; }\n' "$app_host_re" "$APP_URL" \
    > /etc/nginx/mam-canonical-host.conf

run() { su -s /bin/bash www-data -c "php artisan $*"; }
run migrate --force --seed --no-interaction
run optimize

log "MAM is ready: ${APP_URL}"
exec "$@"
