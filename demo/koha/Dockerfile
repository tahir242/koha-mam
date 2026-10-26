# Koha from the official Koha Debian packages, prepared for the MAM demo.
# buildpack-deps:*-curl is Debian with ca-certificates, curl and gnupg already installed.
FROM buildpack-deps:trixie-curl
ARG KOHA_VERSION=26.05
ENV DEBIAN_FRONTEND=noninteractive

# HTTPS package mirrors: some networks block plain-HTTP downloads.
RUN sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list.d/debian.sources \
 && curl -fsSL https://debian.koha-community.org/koha/gpg.asc | gpg --dearmor -o /usr/share/keyrings/koha.gpg \
 && echo "deb [signed-by=/usr/share/keyrings/koha.gpg] https://debian.koha-community.org/koha ${KOHA_VERSION} main" \
      > /etc/apt/sources.list.d/koha.list \
 && apt-get update \
 && apt-get install -y --no-install-recommends koha-common mariadb-client unzip xmlstarlet \
 && rm -rf /var/lib/apt/lists/* \
 # mpm-itk cannot switch users with capabilities inside a container; let it keep root instead.
 && printf '<IfModule mpm_itk_module>\n    EnableCapabilities Off\n</IfModule>\n' > /etc/apache2/conf-available/itk-docker.conf \
 && a2enconf itk-docker \
 && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf && a2enconf servername

# The MAM plugin: a .kpz placed in demo/koha/plugins/ wins; otherwise the published release is used.
ARG PLUGIN_KPZ_URL=https://github.com/tahir242/koha-media-manager/releases/download/v1.1.0-beta/koha-media-manager-v1.1.0-beta.kpz
COPY plugins/ /opt/mam-plugin/
RUN set -e; cd /opt/mam-plugin; \
    local_kpz=$(ls -1 *.kpz 2>/dev/null | head -n1 || true); \
    if [ -n "$local_kpz" ]; then mv "$local_kpz" plugin.kpz; else curl -fsSL -o plugin.kpz "$PLUGIN_KPZ_URL"; fi; \
    unzip -tq plugin.kpz

COPY setup/ /opt/mam-demo/
COPY entrypoint.sh /usr/local/bin/koha-entrypoint
RUN chmod +x /usr/local/bin/koha-entrypoint

EXPOSE 8080 8081
HEALTHCHECK --interval=10s --timeout=5s --start-period=600s --retries=6 \
  CMD curl -fsS -o /dev/null -H "Host: ${KOHA_STAFF_URL#*://}" "http://localhost:${KOHA_STAFF_PORT:-8081}/cgi-bin/koha/mainpage.pl" || exit 1
ENTRYPOINT ["koha-entrypoint"]
