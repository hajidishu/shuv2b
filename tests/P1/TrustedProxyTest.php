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

    public function testDirectDeploymentsDoNotTrustForwardedHeadersByDefault(): void
    {
        config(['trustedproxy.proxies'=>[]]);
        $this->assertSame('127.0.0.1',$this->request('127.0.0.1','203.0.113.99')->ip());
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
