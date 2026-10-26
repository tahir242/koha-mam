#!/bin/bash
# Koha container for the MAM demo. First boot installs Koha, sample data and the MAM plugin;
# later boots just start the services. Settings come from docker-compose.yml.
set -euo pipefail

INSTANCE=${KOHA_INSTANCE:-library}
SITE_CONF=/etc/koha/sites/$INSTANCE/koha-conf.xml
SETUP=/opt/mam-demo
log() { echo "[koha] $*"; }

# Run a setup script as the instance user; koha-shell starts a clean environment, so pass settings along.
run_setup() {
    local vars="" v
    for v in KOHA_DEMO_USER KOHA_DEMO_PASSWORD KOHA_STAFF_URL KOHA_OPAC_URL KOHA_INSTANCE_ID \
             KOHA_SSO_CLIENT_ID MAM_URL MAM_HEALTH_IP MAM_SHARED_ENV; do
        vars+="$v=$(printf '%q' "${!v:-}") "
    done
    koha-shell "$INSTANCE" -c "env $vars perl $1"
}

log "Waiting for the database at $DB_HOST..."
until mariadb -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" -e 'SELECT 1' "$DB_NAME" >/dev/null 2>&1; do sleep 2; done

# --- Instance configuration (lives in the container, recreated after `docker compose up --build`) ---
if [ ! -f "$SITE_CONF" ]; then
    log "Creating Koha instance '$INSTANCE'..."
    cat > /etc/koha/koha-sites.conf <<EOF
DOMAIN=".localhost"
INTRAPORT="$KOHA_STAFF_PORT"
INTRAPREFIX="staff-"
INTRASUFFIX=""
OPACPORT="$KOHA_OPAC_PORT"
OPACPREFIX="opac-"
OPACSUFFIX=""
ZEBRA_MARC_FORMAT="marc21"
ZEBRA_LANGUAGE="en"
USE_MEMCACHED="yes"
MEMCACHED_SERVERS="127.0.0.1:11211"
MEMCACHED_PREFIX="koha_"
EOF
    echo "$INSTANCE:$DB_USER:$DB_PASSWORD:$DB_NAME:$DB_HOST" > /etc/koha/passwd
    for port in "$KOHA_OPAC_PORT" "$KOHA_STAFF_PORT"; do
        grep -q "^Listen $port\$" /etc/apache2/ports.conf || echo "Listen $port" >> /etc/apache2/ports.conf
    done
    a2enmod -q rewrite cgi headers proxy_http >/dev/null
    service memcached start >/dev/null
    koha-create --use-db --dbhost "$DB_HOST" "$INSTANCE" >/dev/null || true
    [ -f "$SITE_CONF" ] || { log "koha-create failed"; exit 1; }

    # Plugins on, and the plugin's test-only plain-HTTP switch (this demo has no TLS).
    xmlstarlet ed -L \
        -u '//config/enable_plugins' -v 1 \
        -d '//config/media_manager_insecure_http' \
        -s '//config' -t elem -n media_manager_insecure_http -v 1 \
        "$SITE_CONF"
    koha-plack --enable "$INSTANCE" >/dev/null
fi

service memcached start >/dev/null 2>&1 || true
mkdir -p "$(dirname "$MAM_SHARED_ENV")"
chown "$INSTANCE-koha:" "$(dirname "$MAM_SHARED_ENV")"

# --- Database: install on first boot, upgrade otherwise ---------------------------------------------
installed=$(mariadb -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" -N -e \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_name='systempreferences'")
if [ "$installed" = "0" ]; then
    log "Installing the Koha database and demo data (first boot, takes a few minutes)..."
    run_setup "$SETUP/populate.pl" 2> >(grep -v "systempreferences. doesn.t exist" >&2)
    fresh=1
else
    koha-upgrade-schema "$INSTANCE" >/dev/null
    fresh=0
fi

# --- MAM plugin -------------------------------------------------------------------------------------
PLUGIN_DIR=/var/lib/koha/$INSTANCE/plugins
if [ ! -f "$PLUGIN_DIR/Koha/Plugin/Com/Library/MediaManager.pm" ] || [ "$fresh" = "1" ]; then
    log "Installing the MAM plugin..."
    mkdir -p "$PLUGIN_DIR"
    unzip -oq /opt/mam-plugin/plugin.kpz -d "$PLUGIN_DIR"
    chown -R "$INSTANCE-koha:" "$PLUGIN_DIR"
    koha-shell "$INSTANCE" -c "perl /usr/share/koha/bin/devel/install_plugins.pl" >/dev/null
fi
run_setup "$SETUP/link_mam.pl"

if [ "$fresh" = "1" ]; then
    log "Indexing the catalogue..."
    koha-rebuild-zebra -f "$INSTANCE" >/dev/null 2>&1 || log "Zebra indexing reported problems (search may be empty)."
fi

# --- Canonical addresses -----------------------------------------------------------------------------
# Koha's login cookie belongs to one host name, and the MAM sends staff back to KOHA_STAFF_URL. Someone who
# opens http://localhost:8081 (Docker Desktop's port links do) would sign in on the wrong host and see the
# login page again, then a 403. Redirect every other name to the configured address.
staff_host=${KOHA_STAFF_URL#*://}
opac_host=${KOHA_OPAC_URL#*://}
cat > /etc/apache2/conf-available/demo-canonical-host.conf <<EOF
<If "%{SERVER_PORT} == '$KOHA_STAFF_PORT' && tolower(%{HTTP_HOST}) != '$staff_host'">
    Redirect 302 / $KOHA_STAFF_URL/
</If>
<If "%{SERVER_PORT} == '$KOHA_OPAC_PORT' && tolower(%{HTTP_HOST}) != '$opac_host'">
    Redirect 302 / $KOHA_OPAC_URL/
</If>
EOF
a2enconf -q demo-canonical-host

# --- Services -----------------------------------------------------------------------------------------
apache2ctl restart 2>&1 | grep -v AH00558 || true
koha-zebra --start "$INSTANCE" >/dev/null 2>&1 || true
koha-indexer --start "$INSTANCE" >/dev/null 2>&1 || true
koha-worker --start "$INSTANCE" >/dev/null 2>&1 || true
koha-plack --restart "$INSTANCE" >/dev/null 2>&1 || koha-plack --start "$INSTANCE"

log "Koha is ready: staff ${KOHA_STAFF_URL}  OPAC ${KOHA_OPAC_URL}  (user: ${KOHA_DEMO_USER})"
exec tail -F /var/log/koha/"$INSTANCE"/plack-error.log /var/log/koha/"$INSTANCE"/intranet-error.log
