# Shared by init.sh and update.sh. The caller has selected the project directory.
if [ "${V2BOARD_DEPENDENCY_LOCK_PID:-}" != "$$" ]; then
    unset V2BOARD_DEPENDENCY_LOCK_DIR
    if command -v flock >/dev/null 2>&1; then
        exec 9>"$PWD/.v2board-dependency-update.lock"
        if ! flock -n 9; then echo "Another dependency/install update is running." >&2; exit 1; fi
    else
        # Portable development fallback. Supported Linux deployments use flock.
        V2BOARD_DEPENDENCY_LOCK_DIR="$PWD/.v2board-dependency-update.lockdir"
        if ! mkdir "$V2BOARD_DEPENDENCY_LOCK_DIR" 2>/dev/null; then echo "Another update or an unreleased update lock exists: $V2BOARD_DEPENDENCY_LOCK_DIR" >&2; exit 1; fi
        export V2BOARD_DEPENDENCY_LOCK_DIR
    fi
    export V2BOARD_DEPENDENCY_LOCK_PID=$$
fi
if [ -n "${V2BOARD_DEPENDENCY_LOCK_DIR:-}" ]; then
    if [ "$V2BOARD_DEPENDENCY_LOCK_DIR" != "$PWD/.v2board-dependency-update.lockdir" ]; then echo "Invalid inherited update lock." >&2; exit 1; fi
    trap 'rmdir -- "$V2BOARD_DEPENDENCY_LOCK_DIR"' EXIT
fi
v2board_php=${V2BOARD_PHP:-php}
if ! command -v "$v2board_php" >/dev/null 2>&1; then
    echo "PHP CLI not found; set V2BOARD_PHP to its absolute executable path." >&2
    exit 1
fi
if ! "$v2board_php" -r 'exit(PHP_VERSION_ID >= 80000 ? 0 : 1);'; then
    echo "The application requires PHP 8.0 or newer; select the intended CLI with V2BOARD_PHP." >&2
    exit 1
fi
if [ -f composer.phar ]; then
    v2board_composer="$PWD/composer.phar"
elif command -v composer >/dev/null 2>&1; then
    v2board_composer=$(command -v composer)
else
    echo "Install Composer or place composer.phar in the project first." >&2
    exit 1
fi
v2board_runtime_args=()
if [ -n "${V2BOARD_RUNTIME:-}" ]; then
    case "$V2BOARD_RUNTIME" in fpm|webman) ;; *) echo "V2BOARD_RUNTIME must be fpm or webman." >&2; exit 1 ;; esac
    v2board_runtime_args=("--runtime=$V2BOARD_RUNTIME")
fi
v2board_profiles() {
    "$v2board_php" -d display_errors=stderr library/DependencyProfiles.php "$1" "$PWD" "--composer=$v2board_composer" "${v2board_runtime_args[@]}" "${@:2}"
}
