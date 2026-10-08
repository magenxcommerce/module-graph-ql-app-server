# syntax = docker/dockerfile:labs

# =====================================================================
#  FrankenPHP worker for Magento /graphql, built for
#  magenx/Magento-2-docker-configuration (same paths, UID, ini style).
#
#  FrankenPHP embeds PHP and needs a ZTS build, so the stack's NTS
#  dhi.io/php images cannot be reused. PHP and extensions are built in
#  the official FrankenPHP image, then the binary, extensions and the
#  shared libraries they need are copied onto a Docker Hardened Debian
#  base (no shell, no package manager), running as DHI_STACK_UID.
# =====================================================================

ARG FRANKENPHP_IMAGE
ARG FRANKENPHP_RUNTIME_IMAGE
ARG PIE_IMAGE
ARG BUSYBOX_IMAGE

# ---------------------------------------------------------------------
#  Tool images
# ---------------------------------------------------------------------
FROM ${PIE_IMAGE}     AS pie
FROM ${BUSYBOX_IMAGE} AS busybox

# ---------------------------------------------------------------------
#  BUILD: extensions compiled against FrankenPHP's ZTS libphp
# ---------------------------------------------------------------------
FROM ${FRANKENPHP_IMAGE} AS build

ARG FRANKENPHP_EXTENSION
ARG PIE_MODULES
ARG MAGENTO_ROOT_PATH
ARG CURRENT_SYMLINK
ARG DHI_STACK_UID
ARG TIMEZONE

# Build dependencies: install-php-extensions brings its own; PIE (phpredis
# with igbinary + lz4, matching the php service) needs these.
RUN <<EOF
  set -e
  apt-get update
  apt-get install -y --no-install-recommends \
    tzdata gettext-base build-essential autoconf libtool pkg-config git unzip liblz4-dev libcap2-bin
  rm -rf /var/lib/apt/lists/*
EOF

SHELL ["/bin/bash", "-c"]

# Same extension set as the php service (PHP_EXTENSION) plus the ones the
# DHI php image ships built in.
RUN install-php-extensions ${FRANKENPHP_EXTENSION}

COPY --from=pie /pie /usr/local/bin/pie
RUN <<EOF
  set -e
  for pie_module in ${PIE_MODULES}; do
    phpredis=' --enable-redis-igbinary --enable-redis-lz4 --with-liblz4=/usr/'
    pie_module_options="${pie_module%/*}"
    pie install "$pie_module" ${!pie_module_options}
  done
EOF

# Stack-wide php settings (same values as the php service), worker overrides.
COPY zz-worker.ini /tmp/zz-worker.ini
RUN <<EOF
  set -e
  export MAGENTO_ROOT_PATH CURRENT_SYMLINK TIMEZONE
  cp "${PHP_INI_DIR}/php.ini-production" "${PHP_INI_DIR}/php.ini"
  envsubst '${MAGENTO_ROOT_PATH} ${CURRENT_SYMLINK} ${TIMEZONE}' \
    < /tmp/zz-worker.ini > "${PHP_INI_DIR}/conf.d/zz-worker.ini"
  php -m
EOF

# Runtime shared libraries for frankenphp, libphp and every extension.
RUN <<EOF
  set -e
  mkdir -p /tmp/runtime-libs
  for file in /usr/local/bin/frankenphp /usr/local/lib/libphp.so "$(php-config --extension-dir)"/*.so; do
    [ -f "$file" ] || continue
    ldd "$file" 2>/dev/null | awk '/=> \// {print $3}' | while read -r dep; do
      [ -f "/tmp/runtime-libs/$(basename "$dep")" ] || cp -L "$dep" /tmp/runtime-libs/
    done
  done
  cp -L /usr/local/lib/libphp.so /tmp/runtime-libs/
EOF

# App mount point, writable Caddy dirs, timezone. The official binary carries
# cap_net_bind_service (to bind :80); the worker listens on an unprivileged
# port, and with cap_drop: ALL a file capability makes exec fail outright.
RUN <<EOF
  set -e
  setcap -r /usr/local/bin/frankenphp
  mkdir -p ${MAGENTO_ROOT_PATH} /tmp/xdg/config /tmp/xdg/data
  chown -R ${DHI_STACK_UID}:${DHI_STACK_UID} /tmp/xdg
  cp /usr/share/zoneinfo/${TIMEZONE} /tmp/localtime
  echo ${TIMEZONE} > /tmp/timezone
EOF

# =====================================================================
#  RUNTIME
# =====================================================================
FROM ${FRANKENPHP_RUNTIME_IMAGE} AS frankenphp

ARG MAGENTO_ROOT_PATH
ARG DHI_STACK_UID

COPY --from=build /usr/local/bin/frankenphp       /usr/local/bin/frankenphp
COPY --from=build /usr/local/lib/php/             /usr/local/lib/php/
COPY --from=build /usr/local/etc/php/             /usr/local/etc/php/
COPY --from=build /tmp/runtime-libs/              /usr/lib/x86_64-linux-gnu/
COPY --from=build ${MAGENTO_ROOT_PATH}            ${MAGENTO_ROOT_PATH}
COPY --from=build /tmp/timezone                   /etc/timezone
COPY --from=build /tmp/localtime                  /etc/localtime
COPY --from=build --chown=${DHI_STACK_UID}:${DHI_STACK_UID} /tmp/xdg/config /config
COPY --from=build --chown=${DHI_STACK_UID}:${DHI_STACK_UID} /tmp/xdg/data   /data

# busybox only for the healthcheck (wget), as the stack does for its CLI images.
COPY --from=busybox /bin/busybox /bin/busybox

COPY Caddyfile /etc/frankenphp/Caddyfile

ENV PHP_INI_DIR=/usr/local/etc/php \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

USER ${DHI_STACK_UID}:${DHI_STACK_UID}

ENTRYPOINT ["/usr/local/bin/frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
