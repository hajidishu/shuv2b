#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"

if ! command -v git >/dev/null 2>&1 || ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "Please deploy using Git and run this script from the application checkout." >&2
    exit 1
fi
branch=$(git symbolic-ref --quiet --short HEAD) || {
    echo "Detached HEAD: select the intended deployment branch before updating." >&2
    exit 1
}
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "Tracked files have local changes. Back them up and resolve them before updating." >&2
    exit 1
fi
loaded_script=$(git hash-object -- update.sh)
git fetch origin "$branch"
git merge --ff-only FETCH_HEAD

# Bash can keep executing the previous script after Git replaces it on disk.
if [ "$(git hash-object -- update.sh)" != "$loaded_script" ]; then
    echo "Updater changed; restarting the newly fetched update.sh."
    exec bash "$PWD/update.sh" "$@"
fi

php_command=${V2BOARD_PHP:-php}
if ! command -v "$php_command" >/dev/null 2>&1; then
    echo "PHP CLI not found. Set V2BOARD_PHP to the intended PHP executable." >&2
    exit 1
fi
if [ -f composer.phar ]; then
    composer_path="$PWD/composer.phar"
elif command -v composer >/dev/null 2>&1; then
    composer_path=$(command -v composer)
else
    echo "Install Composer before updating." >&2
    exit 1
fi

if [ -f vendor/composer/installed.json ]; then
    # Preserve installed versions and optional runtime packages. This also repairs
    # a missing lock or a lock predating the pinned WebAuthn dependency.
    "$php_command" library/UpdateDependencies.php "$PWD" "--composer=$composer_path" --apply
else
    "$php_command" "$composer_path" install --no-dev --prefer-dist --optimize-autoloader --no-plugins --no-scripts --no-interaction
    "$php_command" library/WebAuthnPatch.php
fi

"$php_command" "$composer_path" check-platform-reqs --no-dev --no-plugins --no-scripts --no-interaction
"$php_command" artisan package:discover --ansi
"$php_command" artisan v2board:update
"$php_command" artisan config:cache
"$php_command" artisan view:clear
"$php_command" artisan horizon:terminate

echo "Code and database update finished. Reload PHP-FPM or restart Webman, restore the scheduler and Horizon supervisor, then verify orders and subscriptions."
