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
if command -v composer >/dev/null 2>&1; then
    composer_command=(composer)
elif [ -f composer.phar ]; then
    composer_command=(php composer.phar)
else
    echo "Install Composer before updating." >&2
    exit 1
fi

git fetch origin "$branch"
git merge --ff-only FETCH_HEAD
if [ -f composer.lock ] && ! grep -q '"name": "lbuchs/webauthn"' composer.lock; then
    # Add this new, pinned dependency without updating the existing framework.
    "${composer_command[@]}" update lbuchs/webauthn --with-dependencies --no-dev --prefer-dist --optimize-autoloader
fi
"${composer_command[@]}" install --no-dev --prefer-dist --optimize-autoloader
"${composer_command[@]}" check-platform-reqs --no-dev
php artisan v2board:update
php artisan config:cache
php artisan view:clear
php artisan horizon:terminate

echo "Code and database update finished. Reload PHP-FPM or restart Webman, restore the scheduler and Horizon supervisor, then verify orders and subscriptions."
