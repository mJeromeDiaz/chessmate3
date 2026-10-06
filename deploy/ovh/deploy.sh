#!/usr/bin/env bash
# Deploys the committed code (HEAD) to the OVH shared host (docs/DEPLOY_OVH.md).
#
# Everything is built here: the host cannot download anything over SSH (outgoing connections are
# blocked there), so the vendor directory and the SPA are sent ready. Then, over SSH: migrations,
# cache, deployment checks.
#
# Usage:  OVH_SSH=<login>@ssh.<cluster>.hosting.ovh.net API_URL=https://api.<domain> deploy/ovh/deploy.sh
# Optional: REMOTE_DIR (default: dontstayrooky, in the hosting's home), PHP_BIN (default: PHP 8.3's).
set -euo pipefail

: "${OVH_SSH:?OVH_SSH=<login>@ssh.<cluster>.hosting.ovh.net}"
: "${API_URL:?API_URL=https://api.<domain>}"
REMOTE_DIR="${REMOTE_DIR:-dontstayrooky}"
PHP_BIN="${PHP_BIN:-/usr/local/php8.3/bin/php}"

ROOT="$(git rev-parse --show-toplevel)"
BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT
REVISION="$(git -C "$ROOT" rev-parse --short HEAD)"

if [ -n "$(git -C "$ROOT" status --porcelain)" ]; then
  echo "Note: uncommitted changes are NOT deployed (only HEAD, $REVISION)."
fi

echo "==> Export of $REVISION"
git -C "$ROOT" archive HEAD api front | tar -x -C "$BUILD"

echo "==> API: production dependencies"
(cd "$BUILD/api" && composer install --no-dev --no-scripts --no-interaction --prefer-dist \
  --optimize-autoloader --classmap-authoritative)

echo "==> SPA: build against $API_URL"
(cd "$BUILD/front" && npm ci --no-audit --no-fund && API_URL="$API_URL" npm run build)

echo "==> Upload"
# Kept on the host: its .env.local (secrets), JWT keys, var/ (cache, logs), installed bundle assets.
rsync -az --delete \
  --exclude '/.env.local' --exclude '/.env.*.local' --exclude '/.env.local.php' \
  --exclude '/config/jwt/*.pem' --exclude '/var/' --exclude '/public/bundles/' \
  --exclude '/tests/' --exclude '/phpunit*' --exclude '/.phpunit.cache/' \
  "$BUILD/api/" "$OVH_SSH:$REMOTE_DIR/api/"
rsync -az --delete "$BUILD/front/dist/spa/" "$OVH_SSH:$REMOTE_DIR/app/"

echo "==> On the host: migrations, cache, checks"
ssh "$OVH_SSH" "set -e; cd $REMOTE_DIR/api \
  && $PHP_BIN bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration \
  && $PHP_BIN bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --configuration=config/migrations/catalog.php \
  && $PHP_BIN bin/console cache:clear \
  && $PHP_BIN bin/console assets:install public \
  && $PHP_BIN bin/console app:deploy:check"

echo "==> Deployed $REVISION. Check the web side too: curl -H 'X-Tick-Token: …' $API_URL/api/ops/check?network=1"
