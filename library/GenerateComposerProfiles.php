<?php
declare(strict_types=1);

namespace Library;

final class GenerateComposerProfiles
{
    public static function manifests(array $common): array
    {
        // TODO(security): assess/backport remaining Laravel 8 fixes and upgrade
        // YAML 4. Published compatibility locks restore installs, not security support.
        $fpm = $common;
        $fpm['require']['php'] = '^8.1';
        $fpm['config']['platform']['php'] = '8.1.0';
        $fpm['extra']['v2board-runtime'] = 'fpm';
        $legacyFpm = $common;
        $legacyFpm['require']['php'] = '>=8.0 <8.1';
        $legacyFpm['config']['platform']['php'] = '8.0.0';
        $legacyFpm['extra']['v2board-runtime'] = 'fpm';
        $legacyFpm['extra']['v2board-php-band'] = '8.0';
        $webman = $common;
        $webman['require']['php'] = '^8.1';
        $webman['require']['joanhey/adapterman'] = '0.7.1';
        $webman['require']['workerman/workerman'] = '5.2.0';
        ksort($webman['require']);
        $webman['config']['platform']['php'] = '8.1.0';
        $webman['extra']['v2board-runtime'] = 'webman';
        return ['fpm' => $fpm, 'fpm-php80' => $legacyFpm, 'webman' => $webman];
    }

    public static function generate(string $root, bool $check): void
    {
        $common = json_decode((string)file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (self::manifests($common) as $runtime => $manifest) {
            $path = $root . '/composer.' . $runtime . '.json';
            $bytes = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if ($check) {
                if (!is_file($path) || file_get_contents($path) !== $bytes) throw new \RuntimeException('Generated profile is stale: ' . basename($path));
            } elseif (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new \RuntimeException('Cannot write ' . $path);
            }
        }
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        GenerateComposerProfiles::generate(dirname(__DIR__), in_array('--check', $argv, true));
        echo "Composer profiles are consistent with the common manifest.\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}
