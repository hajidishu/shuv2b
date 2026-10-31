#!/usr/bin/env bash
set -Eeuo pipefail
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
source library/dependency-shell.sh
loaded_script=$(git hash-object -- update.sh)
git fetch origin "$branch"
if ! git merge-base --is-ancestor HEAD FETCH_HEAD; then
    echo "Deployment history diverged. Resolve it without discarding local commits before updating." >&2
    exit 1
fi
if [ -z "${V2BOARD_UPDATE_SNAPSHOT:-}" ]; then
    V2BOARD_UPDATE_OLD_HEAD=$(git rev-parse HEAD)
    V2BOARD_UPDATE_SNAPSHOT=$(v2board_profiles snapshot)
    export V2BOARD_UPDATE_OLD_HEAD V2BOARD_UPDATE_SNAPSHOT
fi
v2board_update_failed() {
    local failure=$?
    trap - ERR
    v2board_profiles rollback "--snapshot=$V2BOARD_UPDATE_SNAPSHOT" || true
    if [ "$(git rev-parse HEAD)" != "$V2BOARD_UPDATE_OLD_HEAD" ]; then
        # --keep refuses to overwrite new local edits; no hard reset is used.
        git reset --keep "$V2BOARD_UPDATE_OLD_HEAD" || echo "Code rollback needs manual inspection." >&2
    fi
    echo "Update failed; no success was reported. Inspect logs: $(dirname -- "$V2BOARD_UPDATE_SNAPSHOT"). Any database changes require separate inspection before restoring service." >&2
    exit "$failure"
}
trap v2board_update_failed ERR
v2board_profiles preflight --revision=FETCH_HEAD
git merge --ff-only FETCH_HEAD
if [ "$(git hash-object -- update.sh)" != "$loaded_script" ]; then
    echo "Updater changed; restarting the newly fetched update.sh."
    exec bash "$PWD/update.sh" "$@"
fi

v2board_profiles update "--snapshot=$V2BOARD_UPDATE_SNAPSHOT"
"$v2board_php" artisan package:discover --ansi
"$v2board_php" artisan v2board:update
"$v2board_php" artisan config:cache
"$v2board_php" artisan view:clear
"$v2board_php" artisan horizon:terminate
v2board_profiles commit "--snapshot=$V2BOARD_UPDATE_SNAPSHOT"
trap - ERR

echo "Code and database update finished. Reload PHP-FPM or restart Webman, restore the scheduler and Horizon supervisor, then verify orders and subscriptions."
