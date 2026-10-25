<?php

namespace Tests\P1;

use PHPUnit\Framework\TestCase;

class PasskeyPatchTest extends TestCase
{
    private function helper(): void
    {
        $root = getenv('P1_OVERRIDE_ROOT') ?: (getenv('P1_SOURCE_ROOT') ?: dirname(__DIR__, 2));
        require_once $root . '/library/WebAuthnPatch.php';
    }

    public function testAdaptationIsIdempotentOnTheActualInstalledLibrary(): void
    {
        $this->helper();
        $file = (new \ReflectionClass(\lbuchs\WebAuthn\WebAuthn::class))->getFileName();
        $vendor = dirname($file, 4);
        $before = hash_file('sha256', $file);
        $this->assertFalse(\Library\WebAuthnPatch::apply($vendor));
        $this->assertSame($before, hash_file('sha256', $file));
        $this->assertTrue(method_exists(\lbuchs\WebAuthn\WebAuthn::class, 'setAllowedOrigins'));
    }

    public function testUnexpectedDependencyBytesAreNeverOverwritten(): void
    {
        $this->helper();
        $root = sys_get_temp_dir() . '/v2board-passkey-patch-' . bin2hex(random_bytes(8));
        $dir = $root . '/lbuchs/webauthn/src'; mkdir($dir, 0700, true);
        $file = $dir . '/WebAuthn.php'; $bytes = '<?php /* unknown upstream version */'; file_put_contents($file, $bytes);
        try {
            try { \Library\WebAuthnPatch::apply($root); $this->fail('Unexpected dependency must fail closed'); }
            catch (\RuntimeException $expected) { $this->assertSame($bytes, file_get_contents($file)); }
        } finally {
            unlink($file); rmdir($dir); rmdir(dirname($dir)); rmdir(dirname($dir, 2)); rmdir($root);
        }
    }
}
