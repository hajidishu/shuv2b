<?php
declare(strict_types=1);

namespace Library;

use Composer;
use RuntimeException;
use Throwable;

/** CLI-only installation of tested dependency profiles and legacy adoption. */
final class DependencyProfiles
{
    private const STATE = '.v2board-runtime.json';

    private static function need(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
    private static function json(string $path): array
    {
        $value = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::need(is_array($value), 'Invalid JSON: ' . $path);
        return $value;
    }
    private static function write(string $path, array $value, int $mode = 0600): void
    {
        $bytes = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $temp = tempnam(dirname($path), '.v2board-write-');
        self::need(is_string($temp) && file_put_contents($temp, $bytes, LOCK_EX) === strlen($bytes), 'Cannot write ' . $path);
        chmod($temp, $mode);
        self::need(rename($temp, $path), 'Cannot publish ' . $path);
    }
    private static function env(?string $manifest = null): array
    {
        $env = getenv();
        foreach (array_keys($env) as $name) if (str_starts_with($name, 'COMPOSER_')) unset($env[$name]);
        $env['COMPOSER_ALLOW_SUPERUSER'] = '1';
        if ($manifest !== null) $env['COMPOSER'] = $manifest;
        return $env;
    }
    private static function command(array $args, string $cwd, string $log, ?string $manifest = null): int
    {
        $pipes = [];
        $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['redirect', 1]], $pipes, $cwd, self::env($manifest));
        self::need(is_resource($process), 'Cannot start command');
        fclose($pipes[0]);
        return proc_close($process);
    }
    private static function composer(string $path, array $args): array
    {
        return [PHP_BINARY, $path, '--no-plugins', '--no-scripts', '--no-interaction', '--no-ansi', ...$args];
    }
    private static function metadata(string $project): ?array
    {
        $file = $project . '/vendor/composer/installed.json';
        if (!is_file($file)) return null;
        $value = self::json($file);
        self::need(isset($value['packages'], $value['dev']) && is_array($value['packages']), 'Composer 2 installation metadata is required');
        return $value;
    }
    private static function matrix(array $packages): array
    {
        $out = [];
        foreach ($packages as $p) $out[$p['name']] = [$p['version'], $p['source']['reference'] ?? null, $p['dist']['reference'] ?? null];
        ksort($out);
        return $out;
    }
    private static function selection(string $project, ?string $requested): string
    {
        if ($requested !== null) { self::need(in_array($requested, ['fpm', 'webman'], true), 'Runtime must be fpm or webman'); return $requested; }
        if (is_file($project . '/' . self::STATE)) {
            $mode = self::json($project . '/' . self::STATE)['runtime'] ?? null;
            self::need(in_array($mode, ['fpm', 'webman'], true), 'Invalid saved runtime');
            return $mode;
        }
        $installed = self::metadata($project);
        if ($installed !== null) {
            $names = array_column($installed['packages'], 'name');
            // Conservatively keep installed Webman components; this never starts
            // Webman or changes a PHP-FPM/Supervisor configuration.
            if (in_array('joanhey/adapterman', $names, true) || in_array('workerman/workerman', $names, true)) return 'webman';
        }
        return 'fpm';
    }
    private static function profile(string $project, string $runtime): array
    {
        $key = self::profileKey($runtime);
        $manifest = $project . '/composer.' . $key . '.json';
        $lock = $project . '/composer.' . $key . '.lock';
        self::need(is_file($manifest) && is_file($lock), 'Published dependency profile is missing: ' . $runtime);
        $json = self::json($manifest); $locked = self::json($lock);
        self::need(($json['name'] ?? null) === 'v2board/v2board' && isset($locked['packages']), 'Invalid published profile');
        return [$manifest, $json, $locked, hash('sha256', file_get_contents($manifest) . "\0" . file_get_contents($lock))];
    }
    private static function profileKey(string $runtime): string { return $runtime === 'fpm' && PHP_VERSION_ID < 80100 ? 'fpm-php80' : $runtime; }
    private static function snapshot(string $project, string $base): string
    {
        $base = realpath($base);
        self::need(is_string($base) && $base !== $project && !str_starts_with($base . '/', $project . '/'), 'Snapshot directory must exist outside the project');
        $dir = $base . '/v2board-update-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(5));
        self::need(mkdir($dir, 0700), 'Cannot create private snapshot');
        $files = [];
        foreach (['composer.json', 'composer.lock', self::STATE] as $name) {
            self::need(!is_link($project . '/' . $name), 'Managed files must not be symbolic links: ' . $name);
            $exists = is_file($project . '/' . $name); $files[$name] = $exists;
            if ($exists) self::need(copy($project . '/' . $name, $dir . '/' . $name . '.before'), 'Cannot back up ' . $name);
        }
        $vendor = is_dir($project . '/vendor');
        self::need(!$vendor || (!is_link($project . '/vendor') && realpath($project . '/vendor') === $project . '/vendor'), 'vendor must be a real project directory');
        if ($vendor) self::need(self::command(['tar', '-czf', $dir . '/vendor.tar.gz', '-C', $project, 'vendor'], $project, $dir . '/vendor-backup.log') === 0, 'Cannot back up vendor');
        $cache = is_dir($project . '/bootstrap/cache');
        if ($cache) self::need(self::command(['tar', '-czf', $dir . '/cache.tar.gz', '-C', $project, 'bootstrap/cache'], $project, $dir . '/cache-backup.log') === 0, 'Cannot back up bootstrap cache');
        self::write($dir . '/snapshot.json', ['schema' => 1, 'project' => $project, 'files' => $files, 'vendor' => $vendor, 'cache' => $cache, 'status' => 'prepared']);
        return $dir . '/snapshot.json';
    }
    private static function loadSnapshot(string $file, string $project): array
    {
        $snapshot = self::json($file);
        self::need(($snapshot['schema'] ?? null) === 1 && ($snapshot['project'] ?? null) === $project, 'Snapshot belongs to another project or format');
        self::need(($snapshot['status'] ?? '') !== 'committed', 'Snapshot is already committed');
        return $snapshot;
    }
    private static function removeStage(string $path, string $project): void
    {
        self::need(dirname($path) === $project && str_starts_with(basename($path), '.v2board-dependencies-') && !is_link($path), 'Unsafe stage path');
        if (!is_dir($path)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $f) {
            if ($f->isDir() && !$f->isLink()) self::need(rmdir($f->getPathname()), 'Cannot remove stage directory');
            else self::need(unlink($f->getPathname()), 'Cannot remove stage file');
        }
        self::need(rmdir($path), 'Cannot remove stage');
    }
    private static function rollback(string $project, string $file): void
    {
        $s = self::loadSnapshot($file, $project); $dir = dirname($file);
        if ($s['status'] === 'rolled_back') { echo "Dependency rollback was already completed.\n"; return; }
        if (!empty($s['mutated'])) {
            $restore = $project . '/.v2board-dependencies-restore-' . bin2hex(random_bytes(4));
            self::need(mkdir($restore, 0700), 'Cannot create restore directory');
            if ($s['vendor']) {
                self::need(self::command(['tar', '-xzf', $dir . '/vendor.tar.gz', '-C', $restore], $project, $dir . '/vendor-restore.log') === 0, 'Cannot extract vendor backup');
            }
            if (is_dir($project . '/vendor')) self::need(rename($project . '/vendor', $restore . '/failed-vendor'), 'Cannot move failed vendor');
            if ($s['vendor']) self::need(rename($restore . '/vendor', $project . '/vendor'), 'Cannot restore vendor');
            foreach (['composer.lock', self::STATE] as $name) {
                if ($s['files'][$name]) self::need(copy($dir . '/' . $name . '.before', $project . '/' . $name), 'Cannot restore ' . $name);
                elseif (is_file($project . '/' . $name)) self::need(unlink($project . '/' . $name), 'Cannot remove new ' . $name);
            }
            if ($s['cache']) {
                if (is_dir($project . '/bootstrap/cache')) self::need(rename($project . '/bootstrap/cache', $restore . '/failed-cache'), 'Cannot retain failed bootstrap cache');
                self::need(self::command(['tar', '-xzf', $dir . '/cache.tar.gz', '-C', $project], $project, $dir . '/cache-restore.log') === 0, 'Cannot restore bootstrap cache');
            }
            // Keep failed files in a private directory for diagnosis.
            $s['failed_stage'] = $restore;
        }
        $s['status'] = 'rolled_back'; self::write($file, $s);
        echo "Dependency rollback complete. Database changes, if any, require separate inspection.\n";
    }
    private static function candidateLock(array $target, ?array $installed, string $composer): array
    {
        if ($installed === null) return $target;
        require_once __DIR__ . '/UpdateDependencies.php';
        UpdateDependencies::composerAutoload($composer);
        $loader = new Composer\Package\Loader\ArrayLoader(null, true); $dumper = new Composer\Package\Dumper\ArrayDumper();
        $known = array_fill_keys(array_column(array_merge($target['packages'], $target['packages-dev'] ?? []), 'name'), true);
        $dev = array_fill_keys($installed['dev-package-names'] ?? [], true);
        foreach ($installed['packages'] as $package) {
            if (isset($known[$package['name']])) continue;
            // Unknown optional packages remain installed. Their constraints are
            // checked by Composer against the new, tested target dependency set.
            $entry = $dumper->dump($loader->load($package));
            if (isset($dev[$package['name']])) $target['packages-dev'][] = $entry;
            else $target['packages'][] = $entry;
        }
        return $target;
    }
    private static function legacyLock(string $project, string $composer, array $target, array $installed, string $backup): void
    {
        if (is_file($project . '/composer.lock')) return;
        $common = self::json($project . '/composer.json');
        $names = array_fill_keys(array_column($installed['packages'], 'name'), true);
        $missingDev = array_diff(array_keys($common['require-dev'] ?? []), array_keys($names));
        if (!$missingDev) return; // The existing recovery path already handles a complete vendor.
        require_once __DIR__ . '/UpdateDependencies.php'; UpdateDependencies::composerAutoload($composer);
        $loader = new Composer\Package\Loader\ArrayLoader(null, true); $dumper = new Composer\Package\Dumper\ArrayDumper();
        $prod = []; $dev = []; $devNames = array_fill_keys($installed['dev-package-names'] ?? [], true);
        foreach ($installed['packages'] as $p) {
            $entry = $dumper->dump($loader->load($p));
            if (isset($devNames[$p['name']])) $dev[] = $entry; else $prod[] = $entry;
        }
        // Complete absent development metadata from the reviewed published set.
        // A full dry-run below checks its compatibility; it is never installed
        // into a production-only deployment.
        foreach (array_merge($target['packages'], $target['packages-dev'] ?? []) as $p) {
            if (isset($names[$p['name']])) continue;
            if ($p['name'] === 'lbuchs/webauthn') $prod[] = $p; else $dev[] = $p;
        }
        $target['packages'] = $prod; $target['packages-dev'] = $dev;
        $target['content-hash'] = Composer\Package\Locker::getContentHash((string)file_get_contents($project . '/composer.json'));
        $target['minimum-stability'] = $common['minimum-stability'] ?? 'stable';
        $target['prefer-stable'] = $common['prefer-stable'] ?? false;
        $target['platform'] = array_filter($common['require'], fn($k) => !str_contains($k, '/'), ARRAY_FILTER_USE_KEY);
        $target['platform-dev'] = array_filter($common['require-dev'] ?? [], fn($k) => !str_contains($k, '/'), ARRAY_FILTER_USE_KEY);
        if (isset($common['config']['platform'])) $target['platform-overrides'] = $common['config']['platform'];
        else unset($target['platform-overrides']);
        $stage = $backup . '/legacy-lock'; self::need(mkdir($stage, 0700), 'Cannot prepare legacy lock metadata');
        $common['config']['vendor-dir'] = $project . '/vendor';
        self::write($stage . '/composer.json', $common, 0644); self::write($stage . '/composer.lock', $target, 0644);
        foreach ([['validate', '--no-check-publish'], ['install', '--dry-run']] as $i => $args) {
            self::need(self::command(self::composer($composer, $args), $stage, $backup . '/legacy-lock-' . $i . '.log') === 0, 'Cannot complete a compatible legacy lock; see ' . $backup . '/legacy-lock-' . $i . '.log');
        }
        self::need(copy($stage . '/composer.lock', $project . '/composer.lock'), 'Cannot publish completed legacy lock'); chmod($project . '/composer.lock', 0644);
    }
    private static function install(string $project, string $composer, string $runtime, string $snapshotFile, bool $fresh): void
    {
        $s = self::loadSnapshot($snapshotFile, $project); $backup = dirname($snapshotFile);
        [$manifestFile, $manifest, $target, $releaseHash] = self::profile($project, $runtime);
        $installed = self::metadata($project);
        $saved = is_file($project . '/' . self::STATE) ? self::json($project . '/' . self::STATE) : null;
        $legacy = !$fresh && $installed !== null && ($saved === null || (!empty($saved['legacy']) && ($saved['runtime'] ?? null) === $runtime && ($saved['release_hash'] ?? null) === $releaseHash));
        $s['mutated'] = true; self::write($snapshotFile, $s);
        if ($legacy) {
            // First adoption and unchanged legacy releases keep deployed bytes.
            // A changed published profile goes through the normal target path.
            self::legacyLock($project, $composer, $target, $installed, $backup);
            self::need(self::command([PHP_BINARY, $project . '/library/UpdateDependencies.php', $project, $backup, '--composer=' . $composer, '--apply'], $project, $backup . '/legacy-recovery.log') === 0, 'Legacy dependency recovery failed; see ' . $backup . '/legacy-recovery.log');
            $after = self::metadata($project);
            self::write($project . '/' . self::STATE, ['schema' => 1, 'runtime' => $runtime, 'legacy' => true, 'dev' => $after['dev'], 'release_hash' => $releaseHash, 'installed_hash' => hash('sha256', json_encode(self::matrix($after['packages'])))]);
            echo "Legacy installation adopted without replacing its dependency versions.\n";
            return;
        }
        $stage = $project . '/.v2board-dependencies-' . bin2hex(random_bytes(5));
        self::need(mkdir($stage, 0700), 'Cannot create dependency stage'); $s['stage'] = $stage; self::write($snapshotFile, $s);
        self::write($stage . '/composer.json', $manifest, 0644);
        self::write($stage . '/composer.lock', self::candidateLock($target, $installed, $composer), 0644);
        foreach (['app', 'library', 'database', 'tests'] as $name) if (is_dir($project . '/' . $name)) self::need(symlink($project . '/' . $name, $stage . '/' . $name), 'Cannot link staged ' . $name);
        if ($installed !== null) self::need(self::command(['cp', '-a', $project . '/vendor', $stage . '/vendor'], $project, $backup . '/stage-copy.log') === 0, 'Cannot stage installed vendor');
        $dev = $installed !== null ? (bool)$installed['dev'] : false; $profile = $dev ? [] : ['--no-dev'];
        foreach ([['validate', '--no-check-publish'], ['check-platform-reqs', '--lock', ...$profile], ['install', '--dry-run', ...$profile], ['install', '--prefer-dist', '--optimize-autoloader', ...$profile]] as $index => $args) {
            self::need(self::command(self::composer($composer, $args), $stage, $backup . '/stage-' . $index . '.log') === 0, 'Staged dependency check/install failed; see ' . $backup . '/stage-' . $index . '.log');
        }
        self::need(self::command([PHP_BINARY, $project . '/library/WebAuthnPatch.php', $stage . '/vendor'], $project, $backup . '/stage-ror.log') === 0, 'Staged ROR adaptation failed');
        if (is_dir($project . '/vendor')) self::need(rename($project . '/vendor', $stage . '/previous-vendor'), 'Cannot retain previous vendor');
        self::need(rename($stage . '/vendor', $project . '/vendor'), 'Cannot activate staged vendor');
        self::need(copy($stage . '/composer.lock', $project . '/composer.lock'), 'Cannot activate instance lock'); chmod($project . '/composer.lock', 0644);
        // Rebuild paths at the final location; generated stage paths must never
        // leak into the activated application's autoloader.
        self::need(self::command(self::composer($composer, ['dump-autoload', '--optimize', ...$profile]), $project, $backup . '/autoload.log', $manifestFile) === 0, 'Final autoloader generation failed');
        self::need(self::command(self::composer($composer, ['check-platform-reqs', ...$profile]), $project, $backup . '/platform.log', $manifestFile) === 0, 'Activated platform requirements fail');
        $check = 'require "vendor/autoload.php"; if (!class_exists("Illuminate\\\\Foundation\\\\Application") || !method_exists("lbuchs\\\\WebAuthn\\\\WebAuthn", "setAllowedOrigins")) exit(1);';
        if ($runtime === 'webman') $check .= 'if (!class_exists("Adapterman\\\\Adapterman") || !class_exists("Workerman\\\\Worker")) exit(2);';
        self::need(self::command([PHP_BINARY, '-r', $check], $project, $backup . '/autoload-check.log') === 0, 'Activated runtime classes cannot be loaded');
        $after = self::metadata($project);
        self::write($project . '/' . self::STATE, ['schema' => 1, 'runtime' => $runtime, 'legacy' => false, 'dev' => $dev, 'release_hash' => $releaseHash, 'installed_hash' => hash('sha256', json_encode(self::matrix($after['packages'])))]);
        echo 'Published ', $runtime, " dependency set installed and verified.\n";
    }

    public static function main(array $argv): void
    {
        umask(0022); // Activated vendor files must remain readable by the web user.
        $action = $argv[1] ?? ''; $project = realpath($argv[2] ?? '');
        self::need(is_string($project) && $project !== '/' && is_file($project . '/composer.json'), 'Invalid project directory');
        $options = [];
        foreach (array_slice($argv, 3) as $argument) { self::need(str_starts_with($argument, '--') && str_contains($argument, '='), 'Invalid option'); [$k, $v] = explode('=', substr($argument, 2), 2); self::need(!isset($options[$k]), 'Duplicate option'); $options[$k] = $v; }
        if ($action === 'snapshot') { echo self::snapshot($project, $options['backup-dir'] ?? (getenv('V2BOARD_UPDATE_BACKUP_DIR') ?: sys_get_temp_dir())), "\n"; return; }
        $snapshot = $options['snapshot'] ?? null;
        if ($action === 'rollback') { self::need(is_string($snapshot), 'Snapshot is required'); self::rollback($project, $snapshot); return; }
        if ($action === 'commit') {
            self::need(is_string($snapshot), 'Snapshot is required'); $s = self::loadSnapshot($snapshot, $project);
            self::need($s['status'] !== 'rolled_back', 'Cannot commit a rolled-back update');
            if (isset($s['stage'])) self::removeStage($s['stage'], $project);
            $s['status'] = 'committed'; self::write($snapshot, $s); echo 'Update snapshot retained: ', dirname($snapshot), "\n"; return;
        }
        self::need(in_array($action, ['install', 'update', 'check', 'preflight'], true), 'Unknown dependency action');
        $composer = realpath($options['composer'] ?? $project . '/composer.phar'); self::need(is_string($composer) && is_file($composer), 'Composer PHP executable/PHAR is required');
        $runtime = self::selection($project, $options['runtime'] ?? null);
        if ($action === 'preflight') {
            $revision = $options['revision'] ?? null;
            if ($revision !== null) {
                self::need(preg_match('/\A[A-Za-z0-9_.\/-]+\z/', $revision) === 1, 'Invalid revision');
                $log = tempnam(sys_get_temp_dir(), 'v2board-manifest-');
                $key = self::profileKey($runtime);
                self::need(self::command(['git', 'show', $revision . ':composer.' . $key . '.json'], $project, $log) === 0, 'Incoming runtime profile is missing');
                $manifest = self::json($log);
                self::need(self::command(['git', 'show', $revision . ':composer.' . $key . '.lock'], $project, $log) === 0, 'Incoming runtime lock is missing');
                $lockBytes = (string)file_get_contents($log);
                self::need(self::command(['git', 'show', $revision . ':composer.' . $key . '.json'], $project, $log) === 0, 'Incoming manifest cannot be read');
                $incomingHash = hash('sha256', file_get_contents($log) . "\0" . $lockBytes);
                self::need(self::command(['git', 'show', $revision . ':composer.json'], $project, $log) === 0, 'Incoming common manifest is missing');
                $common = self::json($log); unlink($log);
            } else { [, $manifest, , $incomingHash] = self::profile($project, $runtime); $common = self::json($project . '/composer.json'); }
            require_once __DIR__ . '/UpdateDependencies.php'; UpdateDependencies::composerAutoload($composer);
            $saved = is_file($project . '/' . self::STATE) ? self::json($project . '/' . self::STATE) : null;
            // Legacy adoption keeps the current package set. Common application
            // PHP requirements still apply before a source checkout is changed.
            self::need(Composer\Semver\Semver::satisfies(PHP_VERSION, $common['require']['php']), 'Incoming application requires PHP ' . $common['require']['php'] . '; current CLI is ' . PHP_VERSION);
            $keepLegacy = self::metadata($project) !== null && ($saved === null || (!empty($saved['legacy']) && $saved['release_hash'] === $incomingHash && $saved['runtime'] === $runtime));
            if (!$keepLegacy) self::need(Composer\Semver\Semver::satisfies(PHP_VERSION, $manifest['require']['php']), 'Selected runtime requires PHP ' . $manifest['require']['php'] . '; current CLI is ' . PHP_VERSION);
            echo 'Preflight runtime: ', $runtime, "\n"; return;
        }
        if ($action === 'check') {
            $saved = is_file($project . '/' . self::STATE) ? self::json($project . '/' . self::STATE) : null;
            self::need($saved !== null, 'No saved runtime; run the updater to adopt this installation');
            [, , , $hash] = self::profile($project, $runtime); self::need($hash === $saved['release_hash'], 'Published dependency set changed; run update.sh');
            $meta = self::metadata($project); self::need($meta !== null && hash('sha256', json_encode(self::matrix($meta['packages']))) === $saved['installed_hash'], 'Installed package state differs from the last verified update');
            self::need(self::command(self::composer($composer, ['check-platform-reqs', ...($saved['dev'] ? [] : ['--no-dev'])]), $project, sys_get_temp_dir() . '/v2board-platform-' . bin2hex(random_bytes(5)) . '.log') === 0, 'Installed platform requirements fail');
            echo 'Runtime ', $runtime, ": dependency state and platform verified.\n"; return;
        }
        self::need(is_string($snapshot), 'Snapshot is required');
        try { self::install($project, $composer, $runtime, $snapshot, $action === 'install'); }
        catch (Throwable $e) { self::rollback($project, $snapshot); throw $e; }
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { DependencyProfiles::main($argv); }
    catch (Throwable $e) { fwrite(STDERR, 'Dependency update failed: ' . $e->getMessage() . "\n"); exit(1); }
}
