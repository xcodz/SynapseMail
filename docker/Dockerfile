# SynapseMail container image (Apache + PHP 8.3), built from the signed release zip on GitHub Releases.
#   docker build --build-arg SYNAPSEMAIL_VERSION=3.5.4 -t synapsemail docker/
# The zip is checked against the SynapseCyber release key (SYNAPSEMAIL-RELEASE.sig) and every file against the
# signed list before it goes into the image. Data lives in /data (config, database, logs, temp).
# Copyright (c) 2026 SynapseCyber.io. Licensed under CC BY-NC-ND 4.0.

ARG PHP_IMAGE=php:8.3-apache

FROM ${PHP_IMAGE} AS release
ARG SYNAPSEMAIL_VERSION
ARG SYNAPSEMAIL_URL=https://github.com/xcodz/SynapseMail/releases/download/v${SYNAPSEMAIL_VERSION}/SynapseMail_v${SYNAPSEMAIL_VERSION}.zip
RUN set -eux; test -n "${SYNAPSEMAIL_VERSION}"; \
    apt-get update; apt-get install -y --no-install-recommends unzip; rm -rf /var/lib/apt/lists/*
COPY verify-release.php /usr/local/bin/
RUN set -eux; \
    curl -fsSL --retry 5 --retry-all-errors -o /tmp/synapsemail.zip "${SYNAPSEMAIL_URL}"; \
    mkdir /app; unzip -q /tmp/synapsemail.zip -d /app; \
    php /usr/local/bin/verify-release.php /app "${SYNAPSEMAIL_VERSION}"

FROM ${PHP_IMAGE}
ARG SYNAPSEMAIL_VERSION
LABEL org.opencontainers.image.title="SynapseMail" \
      org.opencontainers.image.description="SynapseMail webmail for an existing IMAP/SMTP mail server" \
      org.opencontainers.image.source="https://github.com/xcodz/SynapseMail" \
      org.opencontainers.image.url="https://github.com/xcodz/SynapseMail" \
      org.opencontainers.image.vendor="SynapseCyber.io" \
      org.opencontainers.image.licenses="CC-BY-NC-ND-4.0" \
      org.opencontainers.image.version="${SYNAPSEMAIL_VERSION}"

# PHP extensions the installer checks for (intl) and uses when present (zip, gd); keep only their runtime libraries
RUN set -eux; \
    saved="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype-dev; \
    docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype; \
    docker-php-ext-install -j"$(nproc)" intl zip gd; \
    apt-mark auto '.*' >/dev/null; apt-mark manual $saved >/dev/null; \
    find /usr/local -type f -executable -exec ldd '{}' ';' 2>/dev/null \
      | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) next; gsub("^/(usr/)?", "", so); print so }' \
      | sort -u | { xargs -r dpkg-query --search 2>/dev/null || true; } | grep -v "^diversion " | cut -d: -f1 | sort -u | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*; \
    php -m 2>&1 | grep -qi "warning" && exit 1; for e in intl zip gd; do php -m | grep -qx $e; done

RUN set -eux; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    a2enmod rewrite headers expires remoteip setenvif >/dev/null; \
    a2disconf other-vhosts-access-log >/dev/null; \
    sed -ri 's/^ServerTokens .*/ServerTokens Prod/; s/^ServerSignature .*/ServerSignature Off/' /etc/apache2/conf-available/security.conf
COPY php.ini "$PHP_INI_DIR/conf.d/zz-synapsemail.ini"
COPY apache.conf /etc/apache2/sites-available/000-default.conf

# app files are root-owned and read-only for the web server; per-install folders point into /data
COPY --from=release /app /var/www/synapsemail
RUN set -eux; \
    cd /var/www/synapsemail; \
    mkdir -p /usr/local/share/synapsemail; \
    mv config /usr/local/share/synapsemail/config; \
    rm -rf db logs temp setup.key; \
    for d in config db logs temp; do ln -s /data/$d $d; done; \
    ln -s /data/setup.key setup.key; \
    chown -R root:root /var/www/synapsemail /usr/local/share/synapsemail; \
    chmod -R u=rwX,go=rX /var/www/synapsemail /usr/local/share/synapsemail; \
    rm -rf /var/www/html; \
    echo "${SYNAPSEMAIL_VERSION}" > /usr/local/share/synapsemail/VERSION
COPY --chmod=755 entrypoint.sh /usr/local/bin/synapsemail-entrypoint
COPY --chmod=755 setup-key.sh /usr/local/bin/setup-key

ENV SYNAPSEMAIL_TRUSTED_PROXIES="gateway"
VOLUME /data
EXPOSE 80
WORKDIR /var/www/synapsemail
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD curl -fsS -o /dev/null http://127.0.0.1/ || exit 1
ENTRYPOINT ["synapsemail-entrypoint"]
CMD ["apache2-foreground"]
