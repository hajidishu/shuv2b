<?php

namespace Library;

// A versioned, narrowly scoped adaptation of the pinned MIT-licensed library.
// Composer reapplies it after installation; unexpected upstream bytes fail closed.
final class WebAuthnPatch
{
    private const UPSTREAM_SHA256 = '6676f1bbdd8daf0b99a57ebd9855c92d36ddf99494106a4e35e5664e6ceb7c75';

    private static function replacements(): array
    {
        $setter = <<<'PHP'
    /** Explicit, exact web origins authorized by this relying party. */
    public function setAllowedOrigins(array $origins): void {
        if (!$origins || count($origins) > 30) throw new \InvalidArgumentException('Invalid WebAuthn origin allowlist');
        foreach ($origins as $origin) {
            if (!is_string($origin) || $origin === '') throw new \InvalidArgumentException('Invalid WebAuthn origin');
        }
        $this->_allowedOrigins = array_values(array_unique($origins));
    }

PHP;
        $originDoc = "    /**\n     * checks if the origin matchs the RP ID";
        return [
            '    private $_rpIdHash;' => "    private \$_rpIdHash;\n    private \$_allowedOrigins = null;",
            $originDoc => $setter . "\n" . $originDoc,
            '    private function _checkOrigin($origin) {' => "    private function _checkOrigin(\$origin) {\n        if (\$this->_allowedOrigins !== null) return is_string(\$origin) && in_array(\$origin, \$this->_allowedOrigins, true);",
        ];
    }

    public static function apply(string $vendorDirectory): bool
    {
        $path = rtrim($vendorDirectory, '/\\') . '/lbuchs/webauthn/src/WebAuthn.php';
        $source = @file_get_contents($path);
        if ($source === false) throw new \RuntimeException('WebAuthn 2.2.0 未安装，无法应用 ROR 适配');
        $source = str_replace("\r\n", "\n", $source);
        $original = $source;
        $replacements = self::replacements();
        if (hash('sha256', $original) !== self::UPSTREAM_SHA256) {
            $original = str_replace(array_values($replacements), array_keys($replacements), $original);
            if (hash('sha256', $original) !== self::UPSTREAM_SHA256) throw new \RuntimeException('WebAuthn 文件与固定版本不符，拒绝覆盖；请重新安装依赖后重试');
        }
        foreach ($replacements as $before => $after) {
            $original = str_replace($before, $after, $original, $count);
            if ($count !== 1) throw new \RuntimeException('WebAuthn ROR 补丁定位失败');
        }
        if ($source === $original) return false;
        // Keep the deployed file's existing permissions; only its contents change.
        if (file_put_contents($path, $original, LOCK_EX) !== strlen($original)) throw new \RuntimeException('无法写入 WebAuthn ROR 适配');
        return true;
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        WebAuthnPatch::apply($argv[1] ?? dirname(__DIR__) . '/vendor');
        fwrite(STDOUT, "WebAuthn ROR adaptation ready.\n");
    } catch (\Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}
