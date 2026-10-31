#!/usr/bin/env bash
set -Eeuo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"

v2board_install_args=()
for argument in "$@"; do
    case "$argument" in
        --runtime=fpm|--runtime=webman) export V2BOARD_RUNTIME="${argument#--runtime=}" ;;
        --resume) v2board_install_args+=(--resume) ;;
        *) echo "Usage: bash init.sh [--runtime=fpm|webman] [--resume]" >&2; exit 1 ;;
    esac
done
if [ -f .env ] && [ "${#v2board_install_args[@]}" -eq 0 ]; then
    echo "An environment already exists. Use update.sh for an installed site, or init.sh --resume for an interrupted installation." >&2
    exit 1
fi
if [ -f .env ] && [ -z "${V2BOARD_RUNTIME:-}" ] && [ ! -f .v2board-runtime.json ]; then
    echo "Select --runtime=fpm or --runtime=webman explicitly when resuming this installation." >&2
    exit 1
fi
source library/dependency-shell.sh
v2board_profiles preflight
v2board_snapshot=$(v2board_profiles snapshot)
v2board_install_failed() {
    local failure=$?
    trap - ERR
    v2board_profiles rollback "--snapshot=$v2board_snapshot" || true
    echo "Installation failed. Environment/database data were retained for --resume. Logs: $(dirname -- "$v2board_snapshot")" >&2
    exit "$failure"
}
trap v2board_install_failed ERR
v2board_profiles install "--snapshot=$v2board_snapshot"
"$v2board_php" artisan package:discover --ansi
"$v2board_php" artisan v2board:install "${v2board_install_args[@]}"
if [ -f /etc/init.d/bt ] && id www >/dev/null 2>&1; then
    # Preserve Git/Composer ownership; grant the web account its write paths.
    chown -R www storage bootstrap/cache config
fi
v2board_profiles commit "--snapshot=$v2board_snapshot"
trap - ERR
echo "Installation finished. Configure PHP-FPM or the selected Webman daemon, Horizon and the scheduler."
