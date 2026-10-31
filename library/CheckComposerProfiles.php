<?php
declare(strict_types=1);

namespace Library;

/** CI/maintainer check: generated manifests, fresh locks and advisory changes. */
final class CheckComposerProfiles
{
    public static function main(string $root, string $composer): void
    {
        require_once __DIR__ . '/GenerateComposerProfiles.php';
        require_once __DIR__ . '/UpdateDependencies.php';
        GenerateComposerProfiles::generate($root, true);
        UpdateDependencies::composerAutoload($composer);
        $baseline = json_decode((string)file_get_contents($root . '/composer.profile-audit.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['fpm', 'fpm-php80', 'webman'] as $name) {
            $manifest = $root . '/composer.' . $name . '.json';
            $lock = json_decode((string)file_get_contents($root . '/composer.' . $name . '.lock'), true, 512, JSON_THROW_ON_ERROR);
            if (\Composer\Package\Locker::getContentHash((string)file_get_contents($manifest)) !== $lock['content-hash']) throw new \RuntimeException('Profile lock is stale: ' . $name);
            $pipes = [];
            $env = getenv(); $env['COMPOSER'] = $manifest; $env['COMPOSER_ALLOW_SUPERUSER'] = '1';
            unset($env['COMPOSER_NO_AUDIT']);
            $process = proc_open([PHP_BINARY, $composer, '--no-plugins', '--no-scripts', '--no-interaction', 'audit', '--locked', '--no-dev', '--format=json'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
            if (!is_resource($process)) throw new \RuntimeException('Cannot run Composer audit');
            fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $status = proc_close($process);
            $audit = json_decode($out, true);
            if (!is_array($audit) || !isset($audit['advisories'])) throw new \RuntimeException('Audit did not complete for ' . $name . ': ' . $err);
            foreach ($audit['advisories'] as $package => $entries) foreach ($entries as $entry) {
                if (!in_array($entry['advisoryId'], $baseline['profiles'][$name]['advisories'][$package] ?? [], true)) throw new \RuntimeException('Unreviewed security advisory: ' . $name . ' ' . $package . ' ' . $entry['advisoryId']);
            }
            foreach ($audit['abandoned'] ?? [] as $package => $replacement) if (!array_key_exists($package, $baseline['profiles'][$name]['abandoned'])) throw new \RuntimeException('Newly abandoned dependency: ' . $package);
            echo $name, ": lock matches manifest; known legacy advisories remain recorded.\n";
        }
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $composer = realpath($argv[1] ?? dirname(__DIR__) . '/composer.phar');
        if (!is_string($composer)) throw new \RuntimeException('Pass the Composer PHP executable/PHAR');
        CheckComposerProfiles::main(dirname(__DIR__), $composer);
    } catch (\Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
}
