<?php

namespace Tests\P1;

use App\Http\Middleware\TrustProxies;
use App\Services\PasskeyChallenge;
use App\Services\PasskeySchema;
use App\Services\PasskeyService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TrustedProxyTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_ALL);
        parent::tearDown();
    }

    private function request($remote, $forwarded): Request
    {
        $request = Request::create('/', 'POST', ['client_nonce'=>PasskeyService::encode(random_bytes(32))], [], [], [
            'REMOTE_ADDR'=>$remote, 'HTTP_X_FORWARDED_FOR'=>$forwarded,
            'HTTP_ORIGIN'=>'https://example.test', 'HTTP_X_FORWARDED_HOST'=>'forged.example', 'HTTP_X_FORWARDED_PROTO'=>'https',
        ]);
        (new TrustProxies(app('config')))->handle($request, static function () {return new Response();});
        return $request;
    }

    private function proxyConfig(?string $value): array
    {
        $key = 'TRUSTED_PROXIES';
        $original = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        $present = [array_key_exists($key, $_ENV), array_key_exists($key, $_SERVER)];
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            putenv($key . '=' . $value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        try {
            return require dirname(__DIR__, 2) . '/config/trustedproxy.php';
        } finally {
            putenv($original[0] === false ? $key : $key . '=' . $original[0]);
            if ($present[0]) $_ENV[$key] = $original[1]; else unset($_ENV[$key]);
            if ($present[1]) $_SERVER[$key] = $original[2]; else unset($_SERVER[$key]);
        }
    }

    public static function defaultProxyValues(): array
    {
        return ['missing' => [null], 'blank' => [''], 'whitespace' => ['  ']];
    }

    /** @dataProvider defaultProxyValues */
    public function testDefaultsResolveLocalProxyChainsWithoutTrustingExternalClients(?string $value): void
    {
        $configuration = $this->proxyConfig($value);
        $this->assertSame(['127.0.0.1', '::1'], $configuration['proxies']);
        config(['trustedproxy' => $configuration]);
        foreach (['127.0.0.1', '::1'] as $remote) {
            $request = $this->request($remote, '203.0.113.99, 198.51.100.10, ' . $remote);
            $this->assertSame('198.51.100.10', $request->ip());
            $this->assertSame('https', $request->getScheme());
            $this->assertNotSame('forged.example', $request->getHost());
        }
        $this->assertSame('198.51.100.20', $this->request('198.51.100.20', '203.0.113.99')->ip());
        $this->assertSame('127.0.0.1', $this->request('127.0.0.1', '')->ip());
    }

    public function testExplicitProxyListReplacesTheLoopbackDefault(): void
    {
        $configuration = $this->proxyConfig(' 10.20.0.2, 10.30.0.0/24, , ::1 ');
        $this->assertSame(['10.20.0.2', '10.30.0.0/24', '::1'], $configuration['proxies']);
        config(['trustedproxy' => $configuration]);
        $this->assertSame('198.51.100.10', $this->request('10.20.0.2', '198.51.100.10')->ip());
        $this->assertSame('198.51.100.10', $this->request('10.30.0.4', '198.51.100.10')->ip());
        $this->assertSame('127.0.0.1', $this->request('127.0.0.1', '198.51.100.10')->ip());
    }

    public function testOnlyConfiguredProxiesCanSupplyClientAddresses(): void
    {
        config(['trustedproxy.proxies'=>['127.0.0.1','::1']]);
        $request=$this->request('127.0.0.1','198.51.100.10, 127.0.0.1');
        $this->assertSame('198.51.100.10',$request->ip());
        $this->assertNotSame('forged.example',$request->getHost());
        $this->assertSame('https',$request->getScheme());
        $this->assertSame('198.51.100.20',$this->request('198.51.100.20','203.0.113.99')->ip());
        $this->assertSame('198.51.100.10',$this->request('127.0.0.1','203.0.113.99, 198.51.100.10, 127.0.0.1')->ip());
    }

    public function testForwardedHeaderTrustCanBeExplicitlyDisabled(): void
    {
        $configuration = $this->proxyConfig('false');
        $this->assertSame([], $configuration['proxies']);
        config(['trustedproxy' => $configuration]);
        $this->assertSame('127.0.0.1',$this->request('127.0.0.1','203.0.113.99')->ip());
    }

    public function testSubscriptionAuditPersistsTheClientIpAcrossTheQueue(): void
    {
        config(['trustedproxy' => $this->proxyConfig('')]);
        (new \App\Services\SubscriptionAuditSchema())->ensure();
        $request = $this->request('127.0.0.1', '198.51.100.10, 127.0.0.1');
        $request->user = $this->user(['token' => bin2hex(random_bytes(16))]);
        (new \App\Http\Middleware\SubscriptionAudit())->handle($request, static function () {
            return new Response('fixture subscription');
        });
        $job = Environment::$queue->pushed(\App\Jobs\WriteSubscriptionAuditJob::class)->last();
        $this->assertInstanceOf(\App\Jobs\WriteSubscriptionAuditJob::class, $job);
        $job->handle(new \App\Services\SubscriptionAuditService());
        $record = \Illuminate\Support\Facades\DB::table(\App\Services\SubscriptionAuditService::TABLE)
            ->where('event_id', $job->event['event_id'])->first();
        $this->assertSame('198.51.100.10', $record->ip);
        $this->assertSame('127.0.0.1', json_decode($record->request_details, true)['remote_ip']);
    }

    public function testDifferentClientsNoLongerShareThePasskeyQuota(): void
    {
        config(['trustedproxy.proxies'=>['127.0.0.1','::1'], 'v2board.passkey_enabled'=>1,
            'v2board.passkey_rp_id'=>'example.test','v2board.passkey_origins'=>['https://example.test']]);
        (new PasskeySchema())->ensure();
        $first=$this->request('127.0.0.1','198.51.100.10, 127.0.0.1');
        $second=$this->request('127.0.0.1','198.51.100.20, 127.0.0.1');
        $service=new PasskeyService(new PasskeyChallenge());
        for($i=0;$i<30;$i++)$service->loginOptions($first);
        $this->assertArrayHasKey('transaction_id',$service->loginOptions($second));
        try {$service->loginOptions($first);$this->fail('The exhausted client must still be limited');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(429,$e->getStatusCode());}
    }
}
