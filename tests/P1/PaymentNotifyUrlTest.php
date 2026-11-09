<?php
namespace Tests\P1;

use App\Http\Controllers\V1\Admin\PaymentController;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Validation\ValidationException;

class PaymentNotifyUrlTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Environment::$capsule->schema()->table('v2_payment', function ($table) { $table->integer('sort')->default(0); });
        config(['v2board.app_url' => 'https://panel.example.test', 'v2board.payment_return_url' => 'https://customer.example.test']);
        $request = Request::create('https://unexpected.example.test/api/v1/user/order/checkout', 'POST');
        $request->headers->set('X-Forwarded-Host', 'forwarded.example.test');
        $request->headers->set('Origin', 'https://origin.example.test');
        $request->headers->set('Referer', 'https://referer.example.test/order');
        Environment::$app->instance('request', $request);
        Environment::$app->instance('url', new UrlGenerator(new RouteCollection(), $request));
    }

    private function gateway($method = 'EPay', $domain = null)
    {
        return Payment::create(['uuid' => 'notify-fixture', 'payment' => $method, 'notify_domain' => $domain,
            'config' => ['pid' => 'fixture-app', 'key' => 'fixture-secret', 'method_uuid' => 'fixture-method', 'url' => 'https://gateway.example.test']]);
    }

    private function orderData(): array
    {
        return ['trade_no' => 'notify-order', 'total_amount' => 1999, 'user_id' => 1, 'stripe_token' => null];
    }

    public function testBlankOverrideUsesConfiguredSiteEvenWhenRequestAndForwardingHostsDiffer()
    {
        // The real framework URL helper remains hostile; payment URLs must not use it.
        $this->assertStringStartsWith('https://unexpected.example.test/', url('/probe'));
        foreach ([null, ''] as $domain) {
            $this->assertSame('https://panel.example.test/api/v1/guest/payment/notify/PaytaroQR/notify-fixture',
                PaymentService::notifyUrl('PaytaroQR', 'notify-fixture', $domain));
        }
    }

    public function testExplicitHttpsOverridePreservesDeploymentPrefixAndPort()
    {
        $this->assertSame('https://notify.example.test:8443/panel/api/v1/guest/payment/notify/EPay/id',
            PaymentService::notifyUrl('EPay', 'id', 'https://Notify.Example.test:8443/panel/'));
        $this->assertSame('https://notify.example.test/api/v1/guest/payment/notify/EPay/id',
            PaymentService::notifyUrl('EPay', 'id', 'https://Notify.Example.test:443/'));
    }

    public function invalidBases(): array
    {
        return array_map(fn($v) => [$v], ['//notify.example.test', 'ftp://notify.example.test',
            'https://user:password@notify.example.test', 'https://notify.example.test?redirect=other', 'https://notify.example.test#fragment',
            "https://notify.example.test\r\nX-Test: value", 'https://notify.example.test/%0d%0a',
            'https://notify.example.test/../other', 'https://notify.example.test/path\\other', [], false]);
    }

    /** @dataProvider invalidBases */
    public function testInvalidExplicitOverrideIsRejectedInsteadOfFallingBack($base)
    {
        $this->expectException(ValidationException::class);
        PaymentService::notifyUrl('PaytaroQR', 'id', $base);
    }

    public function testMissingSiteDoesNotFallBackToRequestHost()
    {
        config(['v2board.app_url' => null]);
        $this->expectException(ValidationException::class);
        PaymentService::notifyUrl('PaytaroQR', 'id');
    }

    public function testHttpSiteAndExplicitOverrideRemainSupported()
    {
        config(['v2board.app_url' => 'http://panel.example.test']);
        $this->assertSame('http://panel.example.test/api/v1/guest/payment/notify/PaytaroQR/id', PaymentService::notifyUrl('PaytaroQR', 'id', ''));
        $this->assertSame('http://notify.example.test/api/v1/guest/payment/notify/PaytaroQR/id', PaymentService::notifyUrl('PaytaroQR', 'id', 'http://notify.example.test'));
    }

    public function testRealEpayRequestKeepsFixedCallbackAndIndependentFrontendReturnUrl()
    {
        $gateway = $this->gateway();
        $result = (new PaymentService('EPay', $gateway->id))->pay($this->orderData());
        parse_str(parse_url($result['data'], PHP_URL_QUERY), $params);
        $this->assertSame('https://panel.example.test/api/v1/guest/payment/notify/EPay/notify-fixture', $params['notify_url']);
        $this->assertSame('https://customer.example.test/#/order/notify-order', $params['return_url']);
    }

    public function testPaytaroQrAdapterReceivesFixedNotifyUrlWithoutRemotePayment()
    {
        $gateway = $this->gateway('PaytaroQR');
        $service = new PaymentService('PaytaroQR', $gateway->id);
        $capture = new class {
            public $orders = [];
            public function pay($order) { $this->orders[] = $order; return ['type' => 0, 'data' => 'fixture-qr']; }
        };
        $property = new \ReflectionProperty($service, 'payment'); $property->setAccessible(true); $property->setValue($service, $capture);
        $service->pay($this->orderData());
        $this->assertCount(1, $capture->orders);
        $this->assertSame('https://panel.example.test/api/v1/guest/payment/notify/PaytaroQR/notify-fixture', $capture->orders[0]['notify_url']);
        config(['v2board.app_url' => '']);
        try { $service->pay($this->orderData()); $this->fail('Missing fixed base must stop before the adapter'); }
        catch (ValidationException $error) { $this->assertCount(1, $capture->orders); }
    }

    public function testAdminListMatchesCheckoutAndInvalidLegacyEntriesRemainEditable()
    {
        $gateway = $this->gateway();
        $rows = (new PaymentController())->fetch()->getData(true)['data'];
        $this->assertSame(PaymentService::notifyUrl('EPay', $gateway->uuid), $rows[0]['notify_url']);
        $gateway->notify_domain = 'not-a-url'; $gateway->save();
        $rows = (new PaymentController())->fetch()->getData(true)['data'];
        $this->assertSame($gateway->id, $rows[0]['id']); $this->assertNull($rows[0]['notify_url']);
    }

    public function testAdminSaveAllowsBlankOverrideAndRejectsUnsafeDestinations()
    {
        $controller = new PaymentController();
        $payload = ['name' => 'Notify fixture', 'payment' => 'EPay', 'config' => ['key' => 'fixture-secret'], 'notify_domain' => ''];
        $controller->save($this->saveRequest($payload));
        $this->assertSame(1, Payment::count());
        foreach (['ftp://notify.example.test', 'https://notify.example.test?query=1', 'https://user@notify.example.test'] as $domain) {
            try { $controller->save($this->saveRequest(array_replace($payload, ['notify_domain' => $domain]))); $this->fail('Unsafe base saved'); }
            catch (ValidationException $error) { $this->assertArrayHasKey('notify_domain', $error->errors()); }
        }
        $this->assertSame(1, Payment::count());
    }

    public function testCustomDomainCanBeUsedWithoutAValidDefaultNotifyBase()
    {
        config(['v2board.app_url' => null]);
        $this->assertSame('https://notify.example.test/api/v1/guest/payment/notify/EPay/id',
            PaymentService::notifyUrl('EPay', 'id', 'https://notify.example.test'));
    }

    public function testAdminCanSaveAndPayUsingIndependentUrlsWithoutSiteAddress()
    {
        config(['v2board.app_url' => null]);
        (new PaymentController())->save($this->saveRequest(['name' => 'Separated fixture', 'payment' => 'EPay',
            'config' => ['pid' => 'fixture-app', 'key' => 'fixture-secret', 'url' => 'https://gateway.example.test'],
            'notify_domain' => 'https://notify.example.test']));
        $gateway = Payment::firstOrFail();
        $result = (new PaymentService('EPay', $gateway->id))->pay($this->orderData());
        parse_str(parse_url($result['data'], PHP_URL_QUERY), $params);
        $this->assertSame('https://notify.example.test/api/v1/guest/payment/notify/EPay/' . $gateway->uuid, $params['notify_url']);
        $this->assertSame('https://customer.example.test/#/order/notify-order', $params['return_url']);
    }

    public function testAdminStillRejectsMissingConfiguredNotificationBase()
    {
        config(['v2board.app_url' => null]);
        $this->expectException(ValidationException::class);
        try {
            (new PaymentController())->save($this->saveRequest(['name' => 'No notify fixture', 'payment' => 'EPay',
                'config' => ['key' => 'fixture-secret'], 'notify_domain' => '']));
        } finally {
            $this->assertSame(0, Payment::count());
        }
    }

    public function testAdminPartialUpdateKeepsStoredNotifyDomainWithoutSiteAddress()
    {
        config(['v2board.app_url' => null]);
        $gateway = $this->gateway('EPay', 'https://notify.example.test');
        $payload = ['id' => $gateway->id, 'name' => 'Renamed fixture', 'payment' => 'EPay',
            'config' => $gateway->config];
        (new PaymentController())->save($this->saveRequest($payload));
        $this->assertSame('https://notify.example.test', $gateway->fresh()->notify_domain);
        $this->assertSame('Renamed fixture', $gateway->fresh()->name);
        try {
            (new PaymentController())->save($this->saveRequest($payload + ['notify_domain' => '']));
            $this->fail('Clearing the only configured notify base must fail');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('notify_domain', $error->errors());
        }
        $this->assertSame('https://notify.example.test', $gateway->fresh()->notify_domain);
    }

    public function testUnrelatedPartialUpdateDoesNotRevalidateUnchangedLegacyNotifyDomain()
    {
        config(['v2board.app_url' => null]);
        $gateway = $this->gateway('EPay', 'not-a-url');
        (new PaymentController())->save($this->saveRequest(['id' => $gateway->id, 'name' => 'Legacy renamed',
            'payment' => 'EPay', 'config' => $gateway->config]));
        $this->assertSame('Legacy renamed', $gateway->fresh()->name);
        $this->assertSame('not-a-url', $gateway->fresh()->notify_domain);
        $this->expectException(ValidationException::class);
        (new PaymentService('EPay', $gateway->id))->pay($this->orderData());
    }

    public function testInvalidLegacyGatewayCanBeDisabledButNotReenabled()
    {
        $gateway = $this->gateway('EPay', 'not-a-url');
        $controller = new PaymentController();
        $controller->show(Request::create('/', 'POST', ['id' => $gateway->id]));
        $this->assertSame(0, (int)$gateway->fresh()->enable);
        try { $controller->show(Request::create('/', 'POST', ['id' => $gateway->id])); $this->fail('Unsafe gateway enabled'); }
        catch (ValidationException $error) { $this->assertSame(0, (int)$gateway->fresh()->enable); }
    }

    private function saveRequest(array $payload): Request
    {
        $request = new class extends Request {
            public function validate(array $rules, ...$params) { return app('validator')->make($this->all(), $rules, $params[0] ?? [])->validate(); }
        };
        $request->initialize([], $payload);
        $request->setMethod('POST');
        return $request;
    }

    public function testAdminSaveRetainsHttpConfiguration()
    {
        $controller = new PaymentController();
        $controller->save($this->saveRequest(['name' => 'HTTP fixture', 'payment' => 'EPay', 'config' => ['key' => 'fixture-secret'], 'notify_domain' => 'http://notify.example.test']));
        $this->assertSame('http://notify.example.test', Payment::first()->notify_domain);
    }
}
