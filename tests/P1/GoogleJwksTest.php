<?php

namespace Tests\P1;

use App\Services\GoogleTokenVerifier;
use App\Services\PasskeyService;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class GoogleJwksTest extends TestCase
{
    private static $private;
    private $keys;
    private const CLIENT = '123456789012-fixture.apps.googleusercontent.com';

    protected function setUp(): void
    {
        Environment::boot(false);
        if (!self::$private) self::$private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $rsa = openssl_pkey_get_details(self::$private)['rsa'];
        $this->keys = ['keys' => [['kty' => 'RSA', 'alg' => 'RS256', 'kid' => 'fixture-key',
            'n' => PasskeyService::encode($rsa['n']), 'e' => PasskeyService::encode($rsa['e'])]]];
        $this->fake(fn() => $this->response());
    }

    protected function tearDown(): void { Carbon::setTestNow(); }

    private function fake(callable $callback): void
    {
        Http::swap(new Factory());
        Http::fake(function ($request) use ($callback) {
            $this->assertSame('https://www.googleapis.com/oauth2/v3/certs', $request->url());
            return $callback();
        });
    }

    private function response(int $ttl = 3600)
    {
        return Http::response($this->keys, 200, ['Cache-Control' => 'public, max-age=' . $ttl]);
    }

    private function verify(string $kid = 'fixture-key'): array
    {
        $token = JWT::encode(['iss' => 'https://accounts.google.com', 'aud' => self::CLIENT,
            'sub' => 'fixture-user', 'email' => 'fixture@gmail.com', 'email_verified' => true,
            'iat' => time(), 'exp' => time() + 3600, 'nonce' => 'fixture-nonce'], self::$private, 'RS256', $kid);
        return (new GoogleTokenVerifier())->verify($token, 'fixture-nonce', self::CLIENT);
    }

    private function rejected(callable $action, int $status): void
    {
        try { $action(); $this->fail('Expected a rejected verification'); }
        catch (HttpException $error) { $this->assertSame($status, $error->getStatusCode()); }
    }

    public function testFailedFetchUsesShortBackoffAndThenRecovers(): void
    {
        $requests = 0;
        $this->fake(function () use (&$requests) {
            return ++$requests === 1 ? Http::response([], 503) : $this->response();
        });
        $this->rejected(fn() => $this->verify(), 503);
        $this->rejected(fn() => $this->verify(), 503);
        Http::assertSentCount(1);
        Carbon::setTestNow(Carbon::now()->addSeconds(6));
        $this->assertSame('fixture-user', $this->verify()['subject']);
        Http::assertSentCount(2);
    }

    public function testUnknownKidsCannotForceRepeatedKeyDownloads(): void
    {
        $this->rejected(fn() => $this->verify('unknown-one'), 422);
        $this->rejected(fn() => $this->verify('unknown-two'), 422);
        $this->assertSame('fixture-user', $this->verify()['subject']);
        Http::assertSentCount(1);
    }

    public function testCooldownDoesNotOutliveAShortLivedKeyCache(): void
    {
        $this->fake(fn() => $this->response(1));
        $this->verify();
        Carbon::setTestNow(Carbon::now()->addSeconds(2));
        $this->assertSame('fixture-user', $this->verify()['subject']);
        Http::assertSentCount(2);
    }

    public function testExistingCachedKeyWorksDuringFailedRefreshBackoff(): void
    {
        $this->verify();
        Carbon::setTestNow(Carbon::now()->addSeconds(31));
        $this->fake(fn() => Http::response([], 503));
        $this->rejected(fn() => $this->verify('rotated-key'), 503);
        $this->assertSame('fixture-user', $this->verify()['subject']);
        Http::assertSentCount(1);
    }

    public function testLockTimeoutIsTemporaryUnavailabilityNotInvalidCredentials(): void
    {
        $lock = Cache::lock('GOOGLE_LOGIN_JWKS_V1_LOCK', 20);
        $this->assertTrue($lock->get());
        try { $this->rejected(fn() => $this->verify(), 503); }
        finally { $lock->release(); }
        Http::assertNothingSent();
    }

    public function testLegacyColdRefreshGuardDoesNotMisreportInvalidCredentials(): void
    {
        Cache::put('GOOGLE_LOGIN_JWKS_V1_REFRESH', true, 30);
        $this->rejected(fn() => $this->verify(), 503);
        Http::assertNothingSent();
    }

    public function testMalformedDownloadedKeysFailClosedWithTemporaryUnavailability(): void
    {
        $this->fake(fn() => Http::response(['keys' => []], 200));
        $this->rejected(fn() => $this->verify(), 503);
        $this->rejected(fn() => $this->verify(), 503);
        Http::assertSentCount(1);
    }
}
