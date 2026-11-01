<?php
namespace Tests\P1;

use App\Http\Middleware\Client;
use App\Protocols\Surge;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SubscriptionCredentialTest extends DatabaseTestCase
{
    private $victim;
    protected function setUp(): void
    {
        parent::setUp();
        config(['v2board.show_subscribe_method' => 0, 'v2board.show_subscribe_expire' => 5,
            'v2board.subscribe_url' => 'https://panel.example.test', 'v2board.subscribe_path' => '/api/v1/client/subscribe']);
        $this->victim = $this->user(['token' => '2' . str_repeat('a', 31), 'uuid' => '22222222-2222-4222-8222-222222222222',
            'transfer_enable' => 1073741824, 'expired_at' => null]);
        $this->user(['token' => str_repeat('b', 32)]);
    }

    private function request($token, bool $json = true): Request
    {
        return $json ? Request::create('/api/v1/client/subscribe', 'GET', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_USER_AGENT' => 'Surge/5'],
            json_encode(['token' => $token, 'flag' => 'surge'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)) :
            Request::create('/api/v1/client/subscribe', 'GET', ['token' => $token]);
    }

    private function authenticate($token, bool $json = true): Response
    {
        return (new Client())->handle($this->request($token, $json), function ($request) { return new Response((string)$request->user->id); });
    }

    public function invalidTypedCredentials(): array
    {
        $cases = [];
        foreach ([0,1,2] as $mode) foreach ([0,1,2,true,false,2.0,[0],[2],['nested' => 2],null,'',str_repeat('a', 513)] as $value) $cases[] = [$mode,$value];
        return $cases;
    }

    /** @dataProvider invalidTypedCredentials */
    public function testInvalidTypesNeverReachDatabaseLookup($mode, $value): void
    {
        config(['v2board.show_subscribe_method' => $mode]);
        DB::connection()->enableQueryLog(); DB::connection()->flushQueryLog();
        try { $this->authenticate($value); $this->fail('Invalid credential type reached an authenticated handler'); }
        catch (HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        $this->assertSame([], DB::connection()->getQueryLog());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testJsonIntegerCannotAuthenticateAndExposeSurgeSubscriptionUrl(): void
    {
        \Illuminate\Support\Facades\File::swap(new \Illuminate\Filesystem\Filesystem());
        if (!class_exists('File')) class_alias(\Illuminate\Support\Facades\File::class, 'File');
        $_SERVER['HTTP_HOST'] = 'panel.example.test';
        try {
            $response = (new Client())->handle($this->request(2), function ($request) { return new Response((new Surge($request->user, []))->handle()); });
        } catch (HttpException $error) { $this->assertSame(403, $error->getStatusCode()); return; }
        $this->assertStringNotContainsString($this->victim->token, $response->getContent(), 'Unauthorized Surge response leaked the stored subscription token');
        $this->fail('JSON integer authenticated without a subscription credential');
    }

    public function testNumericLookingQueryStringAndCollationAliasesAreNotCredentials(): void
    {
        foreach (['2',strtoupper($this->victim->token)] as $value) foreach ([true,false] as $json) {
            try { $this->authenticate($value, $json); $this->fail('Nonmatching credential accepted'); }
            catch (HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        }
    }

    public function testExactStringCredentialsStillWorkInQueryAndJson(): void
    {
        foreach ([true,false] as $json) $this->assertSame((string)$this->victim->id, $this->authenticate($this->victim->token, $json)->getContent());
        $numeric = $this->user(['token' => str_repeat('2', 32)]);
        $this->assertSame((string)$numeric->id, $this->authenticate($numeric->token)->getContent());
    }

    public function testOneTimeAliasRemainsOneTimeAndRejectsMalformedResolvedValues(): void
    {
        config(['v2board.show_subscribe_method' => 1]);
        Cache::put('otpn_valid-alias', $this->victim->token, 300);
        $this->assertSame((string)$this->victim->id, $this->authenticate('valid-alias')->getContent());
        foreach (['valid-alias','invalid-cache'] as $alias) {
            if ($alias === 'invalid-cache') Cache::put('otpn_' . $alias, 2, 300);
            try { $this->authenticate($alias); $this->fail('Consumed or malformed alias accepted'); }
            catch (HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        }
    }

    public function testTotpColdAndCachedAliasesStillWorkButNonStringCacheValuesCannotAuthenticate(): void
    {
        config(['v2board.show_subscribe_method' => 2]);
        $hash = hash_hmac('sha1', pack('N*', 0) . pack('N*', (int)floor(time() / 300)), $this->victim->token);
        $alias = Helper::base64EncodeUrlSafe($this->victim->id . ':' . $hash);
        $this->assertSame((string)$this->victim->id, $this->authenticate($alias)->getContent());
        $this->assertSame((string)$this->victim->id, $this->authenticate($alias)->getContent());
        foreach ([2,[2],true] as $bad) {
            Cache::put('totp_invalid-cache', $bad, 300);
            try { $this->authenticate('invalid-cache'); $this->fail('Malformed cache credential accepted'); }
            catch (HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        }
    }
}
