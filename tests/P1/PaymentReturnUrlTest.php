<?php
namespace Tests\P1;

use App\Http\Requests\Admin\ConfigSave;
use App\Services\PaymentReturnUrl;
use Illuminate\Http\Request;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

class PaymentReturnUrlTest extends TestCase
{
    protected function setUp(): void
    {
        Environment::boot(false);
        Environment::$app->instance('request', Request::create('https://api.example.test/api/v1/user/order/checkout', 'POST'));
    }

    public function testUnconfiguredSiteKeepsOriginalRoute()
    {
        $this->assertSame('https://api.example.test/#/order/order-1', PaymentReturnUrl::make('order-1'));
    }

    public function testConfiguredFrontendAndEzThemePreserveSubdirectoryAndOriginalOrderNumber()
    {
        config(['v2board.payment_return_url' => 'https://Panel.Example.test:443/customer/',
            'v2board.payment_return_path' => '/#/payment?trade_no={trade_no}']);
        request()->headers->set('Origin', 'https://panel.example.test');
        $this->assertSame('https://panel.example.test/customer/#/payment?trade_no=order%2Fone%3F%23', PaymentReturnUrl::make('order/one?#'));
    }

    public function testSiteAddressIsFallbackAndUnapprovedHeadersCannotChangeIt()
    {
        config(['v2board.app_url' => 'https://panel.example.test/customer']);
        request()->headers->set('Origin', 'https://unapproved.example.test');
        request()->headers->set('Referer', 'https://unapproved.example.test/page');
        request()->headers->set('X-Forwarded-Host', 'unapproved.example.test');
        request()->headers->set('X-Original-Host', 'unapproved.example.test');
        request()->headers->set('X-Forwarded-Proto', 'javascript');
        $this->assertSame('https://panel.example.test/customer/#/order/o1', PaymentReturnUrl::make('o1'));
    }

    public function testAllowlistedOriginCanSelectAnotherFrontend()
    {
        config(['v2board.payment_return_url' => 'https://panel.example.test/customer',
            'v2board.payment_return_origins' => ['https://second.example.test:8443']]);
        request()->headers->set('Origin', 'https://second.example.test:8443');
        $this->assertSame('https://second.example.test:8443/#/order/o1', PaymentReturnUrl::make('o1'));
        request()->headers->set('Origin', 'https://second.example.test');
        $this->assertSame('https://panel.example.test/customer/#/order/o1', PaymentReturnUrl::make('o1'));
    }

    public function testAllowlistedRefererWorksWithoutOriginButCannotOverrideAnUnapprovedOrigin()
    {
        config(['v2board.payment_return_url' => 'https://panel.example.test',
            'v2board.payment_return_origins' => ['https://second.example.test']]);
        request()->headers->set('Referer', 'https://second.example.test/customer/#/payment');
        $this->assertSame('https://second.example.test/#/order/o1', PaymentReturnUrl::make('o1'));
        request()->headers->set('Origin', 'https://unapproved.example.test');
        $this->assertSame('https://panel.example.test/#/order/o1', PaymentReturnUrl::make('o1'));
    }

    public static function invalidBases()
    {
        return array_map(function ($value) { return [$value]; }, [
            'javascript:alert(1)', '//unapproved.example.test', 'ftp://panel.example.test',
            'https://name:secret@panel.example.test', 'https://panel.example.test?next=bad',
            'https://panel.example.test/#/payment', "https://panel.example.test/\r\nheader", [],
            'https://panel.example.test/a/../b', 'https://panel.example.test/a/%2e%2e/b',
            'https://panel.example.test/%0d%0aheader', 'https://panel.example.test/a\\b',
        ]);
    }

    /** @dataProvider invalidBases */
    public function testUnsafeBasesAreRejected($value)
    {
        $this->assertNull(PaymentReturnUrl::normalizeBase($value));
        $rules = (new ConfigSave())->rules();
        $factory = new Factory(Environment::$app['translator'], Environment::$app);
        $this->assertTrue($factory->make(['payment_return_url' => $value], ['payment_return_url' => $rules['payment_return_url']])->fails());
    }

    public static function invalidPaths()
    {
        return array_map(function ($value) { return [$value]; }, [
            'https://unapproved.example.test/{trade_no}', '//unapproved.example.test/{trade_no}',
            '/%2funapproved.example.test/{trade_no}', '/#/payment', '/{trade_no}/{trade_no}',
            '/{unknown}/{trade_no}', "/{trade_no}\r\nLocation:bad", '/a/../{trade_no}',
            '/a/%2e%2e/{trade_no}', '/\\unapproved.example.test/{trade_no}', [],
        ]);
    }

    /** @dataProvider invalidPaths */
    public function testUnsafeTemplatesAreRejected($value)
    {
        $this->assertFalse(PaymentReturnUrl::validPath($value));
        $rules = (new ConfigSave())->rules();
        $factory = new Factory(Environment::$app['translator'], Environment::$app);
        $this->assertTrue($factory->make(['payment_return_path' => $value], ['payment_return_path' => $rules['payment_return_path']])->fails());
    }

    public function testOriginListValidationDoesNotAllowPathsOrWildcards()
    {
        $factory = new Factory(Environment::$app['translator'], Environment::$app);
        $rules = (new ConfigSave())->rules();
        $subset = array_intersect_key($rules, array_flip(['payment_return_origins', 'payment_return_origins.*']));
        foreach (['https://panel.example.test/sub', 'https://*.example.test', '//panel.example.test'] as $entry) {
            $this->assertTrue($factory->make(['payment_return_origins' => [$entry]], $subset)->fails());
        }
        $this->assertFalse($factory->make(['payment_return_origins' => ['https://panel.example.test:443']], $subset)->fails());
    }

    public function testInvalidRuntimeConfigFailsBeforeCreatingPaymentLinks()
    {
        config(['v2board.payment_return_path' => '//unapproved.example.test/{trade_no}']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        PaymentReturnUrl::make('o1');
    }
}
