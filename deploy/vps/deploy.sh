#!/usr/bin/env bash
# Deploys the committed code (HEAD) to the VPS (docs/DEPLOY_VPS.md).
#
# Built here (composer --no-dev, Stockfish and Maia, the SPA), sent by rsync, then over SSH: worker
# stopped, migrations of the three databases, cache, PHP-FPM reloaded (OPcache never re-reads files),
# worker started again, deployment checks.
#
# Usage:  VPS_SSH=deploy@<ip or host> deploy/vps/deploy.sh
# Optional: API_URL (default: https://api.dontstayrooky.com), REMOTE_DIR (default: /srv/dontstayrooky).
set -euo pipefail

: "${VPS_SSH:?VPS_SSH=deploy@<ip or host>}"
API_URL="${API_URL:-https://api.dontstayrooky.com}"
REMOTE_DIR="${REMOTE_DIR:-/srv/dontstayrooky}"

ROOT="$(git rev-parse --show-toplevel)"
BUILD="$(mktemp -d)"
# One SSH connection shared by every rsync and ssh below.
SSH_DIR="$(mktemp -d)"
SSH_OPTS=(-o ControlPath="$SSH_DIR/control" -o ServerAliveInterval=30)
cleanup() {
  ssh "${SSH_OPTS[@]}" -O exit "$VPS_SSH" 2>/dev/null || true
  rm -rf "$BUILD" "$SSH_DIR"
}
trap cleanup EXIT
REVISION="$(git -C "$ROOT" rev-parse --short HEAD)"

if [ -n "$(git -C "$ROOT" status --porcelain)" ]; then
  echo "Note: uncommitted changes are NOT deployed (only HEAD, $REVISION)."
fi

echo "==> Connection to $VPS_SSH"
ssh "${SSH_OPTS[@]}" -o ControlMaster=yes -o ControlPersist=yes -fN "$VPS_SSH"
if ! ssh "${SSH_OPTS[@]}" "$VPS_SSH" "test -f $REMOTE_DIR/api/.env.local"; then
  echo "$REMOTE_DIR/api/.env.local is missing: run deploy/vps/setup.sh on the server first." >&2
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

# The worker would load new classes into its old code while rsync runs: stopped first. The cron's
# reminders only queue messages, which wait for it.
echo "==> On the server: worker stopped"
ssh "${SSH_OPTS[@]}" "$VPS_SSH" 'sudo -n /usr/local/sbin/dontstayrooky-ctl worker-stop'

echo "==> Upload (the first one sends everything: a few minutes)"
# Kept on the server: its .env.local (secrets), JWT keys, var/ (cache, logs, traces), installed bundle assets.
rsync -az --delete --info=progress2 -e "ssh ${SSH_OPTS[*]}" \
  --exclude '/.env.local' --exclude '/.env.*.local' --exclude '/.env.local.php' \
  --exclude '/config/jwt/*.pem' --exclude '/var/' --exclude '/public/bundles/' \
  --exclude '/tests/' --exclude '/phpunit*' --exclude '/.phpunit.cache/' \
  "$BUILD/api/" "$VPS_SSH:$REMOTE_DIR/api/"
rsync -az --delete --info=progress2 -e "ssh ${SSH_OPTS[*]}" "$BUILD/front/dist/spa/" "$VPS_SSH:$REMOTE_DIR/app/"

echo "==> On the server: migrations, cache, PHP-FPM, worker, checks"
ssh "${SSH_OPTS[@]}" "$VPS_SSH" "set -e; cd $REMOTE_DIR/api \
  && php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration \
  && php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --configuration=config/migrations/catalog.php \
  && php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --configuration=config/migrations/endgame.php \
  && php bin/console cache:clear \
  && php bin/console assets:install public \
  && sudo -n /usr/local/sbin/dontstayrooky-ctl reload-php \
  && sudo -n /usr/local/sbin/dontstayrooky-ctl worker-start \
  && php bin/console app:deploy:check"

echo "==> Deployed $REVISION. Check the web side too: curl -H 'X-Tick-Token: …' $API_URL/api/ops/check?network=1"
