#!/usr/bin/env bash
set -euo pipefail
umask 027
cd "$(dirname "$0")/.."
repo="$(pwd -P)"
base="$HOME/promolux"
# This private file defines absolute PHP_BIN and COMPOSER_PHAR paths only.
test -f "$base/deploy.local.env"
source "$base/deploy.local.env"
: "${PHP_BIN:?Set PHP_BIN}" "${COMPOSER_PHAR:?Set COMPOSER_PHAR}"
test -z "$(git status --porcelain)"
commit="$(git rev-parse HEAD)"
test "$(cat "$base/approved-commit")" = "$commit" || { echo 'Commit not approved'; exit 1; }
test -f public/build/manifest.json
test -f "$base/shared/.env"
test "$(df -Pk "$base" | awk 'NR==2 {print $4}')" -ge 307200 || { echo 'Need at least 300 MiB free for staging; also check cPanel account quota'; exit 1; }
"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80500 && PHP_INT_SIZE === 8 && extension_loaded("pdo_mysql") ? 0 : 1);'
mkdir -p "$base/releases"
release="$base/releases/$commit"
test ! -e "$release" || { echo 'Release exists; inspect it manually, never overwrite'; exit 1; }
mkdir "$release"
git archive HEAD | tar -x -C "$release"
cd "$release"
mv storage storage-template
mkdir -p "$base/shared/storage/app/private" "$base/shared/storage/app/public" "$base/shared/storage/framework/cache/data" "$base/shared/storage/framework/sessions" "$base/shared/storage/framework/views" "$base/shared/storage/logs"
ln -s "$base/shared/storage" storage
ln -s "$base/shared/.env" .env
"$PHP_BIN" "$COMPOSER_PHAR" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
"$PHP_BIN" "$COMPOSER_PHAR" check-platform-reqs --no-dev
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if(config("app.version")!=="0.3.1" || config("app.debug") || config("database.default")!=="mariadb") exit(1); echo "Promolux ".config("app.version").PHP_EOL;'
# First deployment deliberately stops before serving an uninitialized database.
test -f "$base/installation-ready" || { echo "Prepared $release. Complete first-install checks in docs/deployment.md before activation."; exit 1; }
test -L "$HOME/public_html/promolux"
test "$(readlink "$HOME/public_html/promolux")" = "$base/current/public"
if test -e "$base/current" && ! test -L "$base/current"; then echo 'current must be a symlink'; exit 1; fi
ln -s "$release" "$base/current.next"
mv -Tf "$base/current.next" "$base/current"
mv "$base/approved-commit" "$base/approved-commit.used"
echo "Activated $commit. Run acceptance checks before allowing staff access."
