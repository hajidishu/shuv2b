<?php
declare(strict_types=1);

namespace Library;

use Composer;
use RuntimeException;
use Throwable;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use FilesystemIterator;

/** Preserve deployed dependency versions while recovering a lost/outdated lock. */
final class UpdateDependencies
{
    private static function stop(string $message): void { throw new RuntimeException($message); }
    private static function need(bool $condition, string $message): void { if (!$condition) self::stop($message); }
    private static function readJson(string $path): array {
        $value = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::need(is_array($value), 'Invalid JSON: ' . $path); return $value;
    }
    private static function writeJson(string $path, array $value): void {
        self::need(file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) !== false, 'Cannot write ' . $path);
    }
    private static function packages(string $project): array {
        $data = self::readJson($project . '/vendor/composer/installed.json');
        self::need(isset($data['packages'], $data['dev']) && is_array($data['packages']), 'Composer 2 installed.json is required');
        return $data;
    }
    private static function matrix(array $packages): array {
        $out = [];
        foreach ($packages as $p) $out[$p['name']] = [$p['version'], $p['source']['reference'] ?? null, $p['dist']['reference'] ?? null];
        ksort($out); return $out;
    }
    private static function contents(string $vendor): array {
        $out = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vendor, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $name = substr($file->getPathname(), strlen($vendor) + 1);
            if (str_starts_with($name, 'composer/') || str_starts_with($name, 'lbuchs/webauthn/') || in_array($name, ['autoload.php', '.htaccess'], true)) continue;
            $out[$name] = $file->isLink() ? 'link:' . readlink($file->getPathname()) : hash_file('sha256', $file->getPathname());
        }
        ksort($out); return $out;
    }
    private static function rootEnv(): array {
        $env = getenv();
        foreach (array_keys($env) as $name) if (str_starts_with($name, 'COMPOSER_')) unset($env[$name]);
        // No project/global settings are changed. Plugins and scripts are disabled below.
        $env['COMPOSER_ALLOW_SUPERUSER'] = '1';
        return $env;
    }
    private static function run(array $args, string $cwd, string $log, array $env): int {
        $pipes = [];
        $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['redirect', 1]], $pipes, $cwd, $env);
        self::need(is_resource($process), 'Cannot start subprocess'); fclose($pipes[0]);
        return proc_close($process);
    }
    private static function plannedAddition(string $text): bool {
        $text = preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $text);
        return preg_match('/^Package operations:\s*1 install,\s*0 updates,\s*0 removals\s*$/m', $text) === 1
            && preg_match('/^\s*- Installing lbuchs\/webauthn \(v?2\.2\.0\)/m', $text) === 1
            && preg_match('/^\s*- (Removing|Upgrading|Downgrading) /m', $text) !== 1;
    }

    private static function composerAutoload(string $composer): void
    {
        $phar = 'phar://' . $composer . '/vendor/autoload.php';
        if (@is_file($phar)) { require_once $phar; return; }
        // Distribution packages can install Composer as PHP source instead of a PHAR.
        $source = stream_resolve_include_path('Composer/autoload.php');
        self::need(is_string($source), 'Cannot load Composer internals; use a Composer PHAR or the distribution Composer package');
        require_once $source;
    }

    public static function handle(array $arguments): void
    {
        $work = null; $changed = false; $committed = false; $project = null; $baseline = null; $originalLock = null; $environment = self::rootEnv();
        try {
            $apply = in_array('--apply', $arguments, true);
            $composerArgs = array_values(array_filter($arguments, fn($s) => str_starts_with($s, '--composer=')));
            self::need(count($composerArgs) <= 1, 'Specify at most one Composer path');
            $positionals = array_values(array_filter(array_slice($arguments, 1), fn($s) => $s !== '--apply' && !str_starts_with($s, '--composer=')));
            self::need(count($positionals) >= 1 && count($positionals) <= 2, 'Usage: php library/UpdateDependencies.php PROJECT [PRIVATE_BACKUP_DIR] [--composer=PATH] [--apply]');
            $project = realpath($positionals[0]);
            self::need(is_string($project) && $project !== '/' && is_file($project . '/composer.json'), 'Invalid project');
            self::need(!is_link($project . '/vendor') && realpath($project . '/vendor') === $project . '/vendor', 'vendor must be a real directory inside the project');
            self::need(!is_link($project . '/composer.lock'), 'composer.lock must not be a symbolic link');
            $originalLock = is_file($project . '/composer.lock') ? (string)file_get_contents($project . '/composer.lock') : null;
            $oldLock = $originalLock !== null ? json_decode($originalLock, true, 512, JSON_THROW_ON_ERROR) : null;
            if ($oldLock !== null) self::need(isset($oldLock['packages']) && is_array($oldLock['packages']), 'Invalid existing lock file');
            $composer = realpath($composerArgs ? substr($composerArgs[0], strlen('--composer=')) : $project . '/composer.phar');
            self::need(is_string($composer) && is_file($composer), 'A Composer PHP executable or PHAR is required');
            $manifestBytes = (string)file_get_contents($project . '/composer.json');
            $manifest = json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR);
            self::need(($manifest['name'] ?? null) === 'v2board/v2board', 'Expected a v2board project');
            self::need(($manifest['require']['lbuchs/webauthn'] ?? null) === '2.2.0', 'Expected the pinned WebAuthn 2.2.0 requirement');
            self::need(!isset($manifest['config']['vendor-dir']) || $manifest['config']['vendor-dir'] === 'vendor', 'Custom vendor directories require manual review');
            self::need(is_file($project . '/library/WebAuthnPatch.php'), 'Missing project ROR adaptation script');
            $before = self::packages($project); $oldMatrix = self::matrix($before['packages']);
            $sdkMissing = !isset($oldMatrix['lbuchs/webauthn']);
            if (!$sdkMissing) self::need(ltrim($oldMatrix['lbuchs/webauthn'][0], 'v') === '2.2.0', 'Installed WebAuthn version differs from the pinned version; refusing an implicit downgrade/upgrade');
            if ($sdkMissing) self::need(!file_exists($project . '/vendor/lbuchs/webauthn'), 'Unmanaged WebAuthn directory requires review');
            if ($oldLock === null) foreach ($manifest['require-dev'] ?? [] as $name => $constraint) {
                if (str_contains($name, '/')) self::need(isset($oldMatrix[$name]), 'Existing metadata does not contain the full development lock information (' . $name . '); this missing-lock repair requires review');
            }
            $baseline = self::contents($project . '/vendor');
            $metadataHash = hash_file('sha256', $project . '/vendor/composer/installed.json');
            $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
            $base = $positionals[1] ?? (getenv('V2BOARD_UPDATE_BACKUP_DIR') ?: sys_get_temp_dir());
            $base = realpath($base); self::need(is_string($base), 'Private work directory must exist');
            self::need($base !== $project && !str_starts_with($base . '/', $project . '/'), 'Work directory must be outside the web project');
            $work = $base . '/v2board-composer-recovery-' . $id;
            self::need(mkdir($work, 0700), 'Cannot create private work directory');
            umask(0022);
            $guard = fopen($project . '/vendor/composer/.v2board-recovery.lock', 'c');
            self::need($guard !== false && flock($guard, LOCK_EX | LOCK_NB), 'Another recovery is running');
            self::need(copy($project . '/composer.json', $work . '/composer.json.original'), 'Cannot back up composer.json');
            self::need(copy($project . '/vendor/composer/installed.json', $work . '/installed.json.original'), 'Cannot back up installed metadata');
            if ($originalLock !== null) self::need(file_put_contents($work . '/composer.lock.original', $originalLock) === strlen($originalLock), 'Cannot back up existing lock');
            echo 'Private work/backup directory: ', $work, "\n";
            $common = [PHP_BINARY, $composer, '--no-plugins', '--no-scripts', '--no-interaction', '--no-ansi'];
            $sdkEntry = null;
            if ($sdkMissing) {
                $sdk = $work . '/sdk'; self::need(mkdir($sdk, 0700), 'Cannot create SDK stage');
                self::writeJson($sdk . '/composer.json', ['name' => 'v2board/recovery-stage', 'require' => ['lbuchs/webauthn' => '2.2.0'],
                    'config' => ['allow-plugins' => false, 'audit' => ['block-insecure' => true], 'policy' => ['advisories' => ['block' => true], 'malware' => ['block' => true]]]]);
                self::need(self::run([...$common, 'install', '--no-dev', '--prefer-dist'], $sdk, $work . '/sdk.log', $environment) === 0, 'Isolated SDK installation failed; see sdk.log');
                $sdkLock = self::readJson($sdk . '/composer.lock');
                self::need(count($sdkLock['packages']) === 1 && $sdkLock['packages'][0]['name'] === 'lbuchs/webauthn', 'SDK unexpectedly requires other packages');
                $sdkEntry = $sdkLock['packages'][0];
                self::need(ltrim($sdkEntry['version'], 'v') === '2.2.0', 'Unexpected SDK version');
                self::need(hash('sha256', str_replace("\r\n", "\n", (string)file_get_contents($sdk . '/vendor/lbuchs/webauthn/src/WebAuthn.php'))) === '6676f1bbdd8daf0b99a57ebd9855c92d36ddf99494106a4e35e5664e6ceb7c75', 'SDK bytes do not match the project pinned version');
            }
            self::composerAutoload($composer);
            $loader = new Composer\Package\Loader\ArrayLoader(null, true);
            $dumper = new Composer\Package\Dumper\ArrayDumper();
            $devNames = array_fill_keys($before['dev-package-names'] ?? [], true);
            $prod = []; $dev = [];
            $byName = [];
            if ($oldLock !== null) foreach (array_merge($oldLock['packages'], $oldLock['packages-dev'] ?? []) as $entry) $byName[$entry['name']] = $entry;
            // Installed metadata is authoritative for deployed bytes, including
            // optional Webman packages which the root manifest no longer lists.
            foreach ($before['packages'] as $entry) $byName[$entry['name']] = $entry;
            $baselinePackages = array_values($byName);
            $lockedDev = $oldLock !== null ? array_fill_keys(array_column($oldLock['packages-dev'] ?? [], 'name'), true) : $devNames;
            $lockedDev += $devNames;
            unset($lockedDev['lbuchs/webauthn']);
            $sdkInLock = false;
            foreach ($baselinePackages as $entry) {
                self::need(isset($entry['name'], $entry['version']), 'Incomplete installed package metadata');
                self::need(($entry['type'] ?? '') !== 'composer-plugin' || isset($entry['source']) || isset($entry['dist']), 'Invalid plugin metadata');
                $entry = $dumper->dump($loader->load($entry));
                if ($entry['name'] === 'lbuchs/webauthn') {
                    self::need(ltrim($entry['version'], 'v') === '2.2.0', 'Locked WebAuthn version differs from the project pin');
                    $sdkInLock = true;
                }
                if (isset($lockedDev[$entry['name']])) $dev[] = $entry; else $prod[] = $entry;
            }
            if (!$sdkInLock) {
                if ($sdkEntry === null) foreach ($before['packages'] as $entry) if ($entry['name'] === 'lbuchs/webauthn') $sdkEntry = $dumper->dump($loader->load($entry));
                self::need(is_array($sdkEntry), 'Cannot determine the WebAuthn package metadata');
                $prod[] = $sdkEntry;
            }
            usort($prod, fn($a, $b) => strcmp($a['name'], $b['name'])); usort($dev, fn($a, $b) => strcmp($a['name'], $b['name']));
            $platform = function (array $requirements): array { return array_filter($requirements, fn($name) => $name === 'php' || $name === 'hhvm' || str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-') || str_starts_with($name, 'composer-'), ARRAY_FILTER_USE_KEY); };
            $lock = ['_readme' => ['Preserved deployed/locked versions; only the pinned WebAuthn requirement may be added.'],
                'content-hash' => Composer\Package\Locker::getContentHash($manifestBytes), 'packages' => $prod, 'packages-dev' => $dev,
                'aliases' => $oldLock['aliases'] ?? [], 'minimum-stability' => $manifest['minimum-stability'] ?? 'stable', 'stability-flags' => $oldLock['stability-flags'] ?? [],
                'prefer-stable' => $manifest['prefer-stable'] ?? false, 'prefer-lowest' => false,
                'platform' => $platform($manifest['require'] ?? []), 'platform-dev' => $platform($manifest['require-dev'] ?? []),
                'plugin-api-version' => Composer\Plugin\PluginInterface::PLUGIN_API_VERSION];
            if (isset($manifest['config']['platform'])) $lock['platform-overrides'] = $manifest['config']['platform'];
            self::writeJson($work . '/composer.lock', $lock);
            $preview = $manifest; $preview['config']['vendor-dir'] = $project . '/vendor';
            self::writeJson($work . '/composer.json', $preview);
            $profile = ($before['dev'] ?? true) ? [] : ['--no-dev'];
            self::need(self::run([...$common, 'validate', '--no-check-publish'], $work, $work . '/lock-validation.log', $environment) === 0, 'Reconstructed lock does not satisfy the root manifest; see lock-validation.log');
            self::need(self::run([...$common, 'check-platform-reqs', ...$profile], $project, $work . '/platform.log', $environment) === 0, 'Installed PHP/platform requirements fail; see platform.log');
            self::need(self::run([...$common, 'install', '--dry-run', ...$profile], $work, $work . '/plan.log', $environment) === 0, 'Locked preview failed; see plan.log');
            $plan = (string)file_get_contents($work . '/plan.log');
            self::need($sdkMissing ? self::plannedAddition($plan) : str_contains($plan, 'Nothing to install, update or remove'), 'Refusing plan: changes to deployed packages are not allowed; see plan.log');
            echo 'Verified plan: ', $sdkMissing ? '1 WebAuthn install' : 'no dependency changes', ", 0 updates, 0 removals.\n";
            self::writeJson($work . '/result.json', ['status' => 'prepared', 'project' => $project, 'installed_package_count' => count($oldMatrix), 'new_package' => 'lbuchs/webauthn:2.2.0', 'apply_requested' => $apply]);
            if (!$apply) { echo "Preparation only; project dependencies and lock file were not changed.\n"; exit(0); }
            $currentLock = is_file($project . '/composer.lock') ? (string)file_get_contents($project . '/composer.lock') : null;
            self::need((string)file_get_contents($project . '/composer.json') === $manifestBytes && hash_file('sha256', $project . '/vendor/composer/installed.json') === $metadataHash && $currentLock === $originalLock, 'Project changed during preparation; refusing apply');
            self::need(self::run(['tar', '-czf', $work . '/vendor-backup.tar.gz', '-C', $project, 'vendor'], $project, $work . '/backup.log', $environment) === 0, 'Vendor backup failed');
            self::need(is_file($work . '/vendor-backup.tar.gz'), 'Vendor backup missing');
            $json = (string)file_get_contents($work . '/composer.lock');
            $candidate = tempnam($project, '.composer-lock-'); self::need(is_string($candidate), 'Cannot stage lock file');
            self::need(file_put_contents($candidate, $json, LOCK_EX) === strlen($json), 'Cannot write complete lock file');
            chmod($candidate, 0644);
            $changed = true;
            self::need(rename($candidate, $project . '/composer.lock'), 'Cannot publish lock file');
            self::need(self::run([...$common, 'install', '--prefer-dist', '--optimize-autoloader', ...$profile], $project, $work . '/install.log', $environment) === 0, 'Locked installation failed; see install.log');
            self::need(self::run([PHP_BINARY, $project . '/library/WebAuthnPatch.php'], $project, $work . '/ror.log', $environment) === 0, 'ROR adaptation failed; see ror.log');
            $after = self::matrix(self::packages($project)['packages']);
            self::need(isset($after['lbuchs/webauthn']) && ltrim($after['lbuchs/webauthn'][0], 'v') === '2.2.0', 'SDK metadata missing after install');
            if ($sdkMissing) unset($after['lbuchs/webauthn']);
            self::need($after === $oldMatrix, 'Existing package versions/references changed');
            self::need(self::contents($project . '/vendor') === $baseline, 'Existing package files changed');
            self::need((string)file_get_contents($project . '/composer.json') === $manifestBytes, 'Root composer.json changed');
            $check = 'require "vendor/autoload.php"; foreach (["Illuminate\\\\Foundation\\\\Application","Symfony\\\\Component\\\\Yaml\\\\Yaml","lbuchs\\\\WebAuthn\\\\WebAuthn"] as $c) if (!class_exists($c)) exit(1); if (!method_exists("lbuchs\\\\WebAuthn\\\\WebAuthn", "setAllowedOrigins")) exit(2); echo "autoload and ROR OK\\n";';
            self::need(self::run([PHP_BINARY, '-r', $check], $project, $work . '/autoload.log', $environment) === 0, 'Post-install autoload verification failed');
            self::need(self::run([...$common, 'install', '--dry-run', ...$profile], $project, $work . '/after-plan.log', $environment) === 0, 'Post-install locked preview failed');
            self::need(str_contains((string)file_get_contents($work . '/after-plan.log'), 'Nothing to install, update or remove'), 'Post-install state still requires changes');
            self::writeJson($work . '/result.json', ['status' => 'repaired', 'project' => $project, 'backup' => $work . '/vendor-backup.tar.gz',
                'existing_packages_and_files_unchanged' => true, 'root_composer_json_unchanged' => true,
                'new_lock_created' => $originalLock === null, 'webauthn_and_ror' => true]);
            $committed = true;
            echo "Dependency update complete: locked versions preserved; WebAuthn and ROR ready.\n";
        } catch (Throwable $error) {
            fwrite(STDERR, 'ERROR: ' . $error->getMessage() . "\n");
            if ($changed && !$committed && $project !== null && $work !== null) {
                $restore = $project . '/.vendor-recovery-restore-' . bin2hex(random_bytes(4));
                if (mkdir($restore, 0700) && self::run(['tar', '-xzf', $work . '/vendor-backup.tar.gz', '-C', $restore], $project, $work . '/rollback.log', $environment) === 0) {
                    $failed = $restore . '/failed-vendor';
                    if (rename($project . '/vendor', $failed)) {
                        if (rename($restore . '/vendor', $project . '/vendor')) {
                            if ($originalLock === null) @unlink($project . '/composer.lock');
                            else file_put_contents($project . '/composer.lock', $originalLock, LOCK_EX);
                            $restoredLock = is_file($project . '/composer.lock') ? (string)file_get_contents($project . '/composer.lock') : null;
                            $restoredPackages = self::matrix(self::packages($project)['packages']);
                            if ($restoredLock === $originalLock && $restoredPackages === $oldMatrix && self::contents($project . '/vendor') === $baseline) fwrite(STDERR, "Rollback: previous vendor restored and verified; previous lock state restored.\n");
                            else fwrite(STDERR, 'ROLLBACK NEEDS ATTENTION: restored file verification failed; ' . $restore . "\n");
                        } else {
                            @rename($failed, $project . '/vendor');
                            fwrite(STDERR, 'ROLLBACK NEEDS ATTENTION: could not restore snapshot; ' . $restore . "\n");
                        }
                    } else fwrite(STDERR, 'ROLLBACK NEEDS ATTENTION: ' . $restore . "\n");
                } else fwrite(STDERR, 'ROLLBACK NEEDS ATTENTION: backup ' . $work . '/vendor-backup.tar.gz' . "\n");
            }
            if ($work !== null) fwrite(STDERR, 'Private logs/backup: ' . $work . "\n");
            exit(1);
        }

    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    UpdateDependencies::handle($argv);
}
