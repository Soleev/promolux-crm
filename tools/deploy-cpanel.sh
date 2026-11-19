#!/usr/bin/env bash
set -euo pipefail
umask 027
cd "$(dirname "$0")/.."
case "${1:-prepare}" in
    prepare|activate) phase="${1:-prepare}" ;;
    *) echo 'Usage: deploy-cpanel.sh [prepare|activate]' >&2; exit 2 ;;
esac
base="$HOME/promolux"
# Private configuration: PHP/Composer paths and approved transition parameters.
test -f "$base/deploy.local.env"
source "$base/deploy.local.env"
: "${PHP_BIN:?Set PHP_BIN}" "${COMPOSER_PHAR:?Set COMPOSER_PHAR}"
test -z "$(git status --porcelain)"
commit="$(git rev-parse HEAD)"
# Operator approval is bound to both ends of the transition.
: "${EXPECTED_CURRENT_SHA:?Set expected current SHA}" "${EXPECTED_CURRENT_VERSION:?Set current version}" "${TARGET_VERSION:?Set target version}"
[[ "$EXPECTED_CURRENT_SHA" =~ ^[0-9a-f]{40}$ ]]
export TARGET_VERSION EXPECTED_CURRENT_VERSION
previous="$base/releases/$EXPECTED_CURRENT_SHA"
test -L "$base/current"
test "$(readlink -f "$base/current")" = "$previous"
test "$("$PHP_BIN" -r '$r=require $argv[1]; echo array_key_first($r);' "$previous/config/releases.php")" = "$EXPECTED_CURRENT_VERSION"
test "$("$PHP_BIN" -r '$r=require "config/releases.php"; echo array_key_first($r);')" = "$TARGET_VERSION"
test "$(cat "$base/approved-commit")" = "$commit" || { echo 'Commit not approved'; exit 1; }
test -f public/build/manifest.json
test -f "$base/shared/.env"
"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80500 && PHP_INT_SIZE === 8 && extension_loaded("pdo_mysql") ? 0 : 1);'
mkdir -p "$base/releases"
release="$base/releases/$commit"
if [[ "$phase" == prepare ]]; then
    test "$(df -Pk "$base" | awk 'NR==2 {print $4}')" -ge 307200 || { echo 'Need at least 300 MiB free for staging; also check cPanel account quota'; exit 1; }
    test ! -e "$release" || { echo 'Release exists; inspect it manually, never overwrite'; exit 1; }
    mkdir -m 700 "$release"
    git archive HEAD | tar -x -C "$release"
    cd "$release"
    mkdir -p "$base/shared/storage/app/private" "$base/shared/storage/app/public" "$base/shared/storage/framework/cache/data" "$base/shared/storage/framework/sessions" "$base/shared/storage/framework/views" "$base/shared/storage/logs"
    ln -s "$base/shared/.env" .env
    CACHE_STORE=array SESSION_DRIVER=array "$PHP_BIN" "$COMPOSER_PHAR" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
    "$PHP_BIN" "$COMPOSER_PHAR" check-platform-reqs --no-dev
    mv storage storage-template
    ln -s "$base/shared/storage" storage
    test -f public/index.php && test -f public/.htaccess || { echo 'Missing public entry point or rewrite rules'; exit 1; }
    test -d public && test ! -L public
    if [[ -n "$(find public ! -type d ! -type f -print -quit)" ]]; then
        echo 'Unexpected link or special file in public directory'; exit 1
    fi
    # PHP runs as the cPanel account. Protect private entries before opening the
    # traversal path for Apache. Never chmod shared storage or the linked .env.
    find . -mindepth 1 -maxdepth 1 ! -name public ! -type l -exec chmod go-rwx {} +
    find public -type d -exec chmod 755 {} +
    find public -type f -exec chmod 644 {} +
    chmod 711 "$release"
    touch "$release/.prepared"
    echo "Prepared $commit; current is unchanged. Follow docs/deployment.md."
    exit 0
fi

test -f "$release/.prepared"
test "$(readlink -f "$release")" = "$release"
test "$(readlink "$release/storage")" = "$base/shared/storage"
test "$(readlink "$release/.env")" = "$base/shared/.env"
cd "$release"
test -f "$base/installation-ready"
test -L "$HOME/public_html/promolux"
test "$(readlink "$HOME/public_html/promolux")" = "$base/current/public"
test ! -e "$base/current.next" && test ! -L "$base/current.next"
test ! -e "$base/previous-$commit"
"$PHP_BIN" artisan config:cache
"$PHP_BIN" -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config("app.version") !== getenv("TARGET_VERSION") || config("app.debug") || config("database.default") !== "mariadb") exit(1);
$db = Illuminate\Support\Facades\DB::class;
foreach (glob("database/migrations/*.php") as $migration) {
    if (! $db::table("migrations")->where("migration", basename($migration, ".php"))->exists()) exit(1);
}
if (! Illuminate\Support\Facades\Schema::hasColumn("tasks", "created_by")) exit(1);
'
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
printf '%s\n' "$previous" > "$base/previous-$commit"
ln -s "$release" "$base/current.next"
mv -Tf "$base/current.next" "$base/current"
mv "$base/approved-commit" "$base/approved-commit.used"
echo "Activated $commit. Run acceptance checks before allowing staff access."
