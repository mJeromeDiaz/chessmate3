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
# One SSH connection shared by every rsync and ssh below: the password is asked once, at the start.
SSH_DIR="$(mktemp -d)"
SSH_OPTS=(-o ControlPath="$SSH_DIR/control" -o ServerAliveInterval=30)
cleanup() {
  ssh "${SSH_OPTS[@]}" -O exit "$OVH_SSH" 2>/dev/null || true
  rm -rf "$BUILD" "$SSH_DIR"
}
trap cleanup EXIT
REVISION="$(git -C "$ROOT" rev-parse --short HEAD)"

if [ -n "$(git -C "$ROOT" status --porcelain)" ]; then
  echo "Note: uncommitted changes are NOT deployed (only HEAD, $REVISION)."
fi

echo "==> Connection to $OVH_SSH (the only password prompt)"
ssh "${SSH_OPTS[@]}" -o ControlMaster=yes -o ControlPersist=yes -fN "$OVH_SSH"
if ! ssh "${SSH_OPTS[@]}" "$OVH_SSH" 'command -v rsync' >/dev/null; then
  echo "rsync is missing on the host: send the files over SFTP instead (docs/DEPLOY_OVH.md)." >&2
  exit 1
fi

echo "==> Export of $REVISION"
git -C "$ROOT" archive HEAD api front | tar -x -C "$BUILD"

echo "==> API: production dependencies"
(cd "$BUILD/api" && composer install --no-dev --no-scripts --no-interaction --prefer-dist \
  --optimize-autoloader --classmap-authoritative)

echo "==> SPA: build against $API_URL"
# The chess engine and Maia are not in git (docs/ENGINE.md): fetched from their releases, digests checked.
(cd "$BUILD/front" && npm ci --no-audit --no-fund && npm run engine:fetch && npm run maia:fetch && API_URL="$API_URL" npm run build)

echo "==> Upload (the first one sends everything: several minutes)"
# Kept on the host: its .env.local (secrets), JWT keys, var/ (cache, logs), installed bundle assets.
rsync -az --delete --info=progress2 -e "ssh ${SSH_OPTS[*]}" \
  --exclude '/.env.local' --exclude '/.env.*.local' --exclude '/.env.local.php' \
  --exclude '/config/jwt/*.pem' --exclude '/var/' --exclude '/public/bundles/' \
  --exclude '/tests/' --exclude '/phpunit*' --exclude '/.phpunit.cache/' \
  "$BUILD/api/" "$OVH_SSH:$REMOTE_DIR/api/"
rsync -az --delete --info=progress2 -e "ssh ${SSH_OPTS[*]}" "$BUILD/front/dist/spa/" "$OVH_SSH:$REMOTE_DIR/app/"

echo "==> On the host: migrations, cache, checks"
ssh "${SSH_OPTS[@]}" "$OVH_SSH" "set -e; cd $REMOTE_DIR/api \
  && $PHP_BIN bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration \
  && $PHP_BIN bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --configuration=config/migrations/catalog.php \
  && $PHP_BIN bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --configuration=config/migrations/endgame.php \
  && $PHP_BIN bin/console cache:clear \
  && $PHP_BIN bin/console assets:install public \
  && $PHP_BIN bin/console app:deploy:check"

echo "==> Deployed $REVISION. Check the web side too: curl -H 'X-Tick-Token: …' $API_URL/api/ops/check?network=1"
