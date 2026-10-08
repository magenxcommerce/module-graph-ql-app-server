#!/bin/bash
# Wire the FrankenPHP GraphQL worker into magenx/Magento-2-docker-configuration.
#
# Run from the stack directory (/opt/BRAND/docker) after scripts/get_nginx_config.sh:
#     bash frankenphp/install.sh
# Safe to re-run: every step checks before it changes anything.

set -euo pipefail

STACK_DIR="$(pwd)"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SITE="${STACK_DIR}/nginx/templates/sites-available/magento2.conf.template"

[ -f "${STACK_DIR}/docker-compose.yml" ] || { echo "Run from the stack directory (docker-compose.yml not found)." >&2; exit 1; }
[ -f "${SITE}" ] || { echo "${SITE} not found: run scripts/get_nginx_config.sh first." >&2; exit 1; }

# 1. nginx templates (rendered into /etc/nginx by the nginx image build).
mkdir -p "${STACK_DIR}/nginx/templates/conf_m2"
cp "${HERE}/nginx/conf_m2/graphql_worker_maps.conf.template" "${STACK_DIR}/nginx/templates/conf_m2/"
cp "${HERE}/nginx/conf_m2/graphql_worker.conf.template" "${STACK_DIR}/nginx/templates/conf_m2/"
echo "nginx: templates copied"

# 2. Include them in the magento2 site: maps at http level, location before `location /`.
if ! grep -q 'conf_m2/graphql_worker_maps.conf' "${SITE}"; then
  sed -i 's|^include conf_m2/maps.conf;|&\ninclude conf_m2/graphql_worker_maps.conf;|' "${SITE}"
fi
if ! grep -q 'conf_m2/graphql_worker.conf;' "${SITE}"; then
  sed -i '0,/^  location \/ {/s||  ## FrankenPHP GraphQL worker, php-fpm fallback\n  include conf_m2/graphql_worker.conf;\n\n  location / {|' "${SITE}"
fi
grep -q 'conf_m2/graphql_worker_maps.conf' "${SITE}" && grep -q 'conf_m2/graphql_worker.conf;' "${SITE}" \
  || { echo "Could not add the includes to ${SITE}; add them by hand (see README)." >&2; exit 1; }
echo "nginx: magento2.conf includes the worker config"

# 3. .env block, once.
if ! grep -q '^FRANKENPHP_IMAGE=' "${STACK_DIR}/.env"; then
  cat "${HERE}/.env.frankenphp" >> "${STACK_DIR}/.env"
  echo ".env: FrankenPHP block appended (pin FRANKENPHP_RUNTIME_IMAGE by digest)"
else
  echo ".env: FrankenPHP block already present"
fi

cat <<'EOF'

Next:
  docker compose -f docker-compose.yml -f frankenphp/docker-compose.frankenphp.yml up -d --build frankenphp
  docker compose up -d --build nginx
After every release switch (public/current):
  docker compose -f docker-compose.yml -f frankenphp/docker-compose.frankenphp.yml restart frankenphp
EOF
