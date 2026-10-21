<?php
namespace Tests\P1;

use App\Http\Controllers\V1\Guest\PaymentController;
use App\Http\Controllers\V1\User\OrderController;
use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderService;
use App\Services\PaymentAttemptService;
use App\Services\PaymentLedgerSchema;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentFlowFixTest extends DatabaseTestCase
{
    private function gateway($method = 'EPay', array $config = [], array $fields = [])
    {
        return Payment::create($fields + ['uuid' => uniqid('flow-'), 'payment' => $method, 'enable' => 1,
            'config' => $config + ['pid' => 'fixture-merchant', 'key' => 'fixture-secret', 'url' => 'https://gateway.example.test']]);
    }
    private function checkout($user, $order, $gateway, $token = null, $origin = 'https://panel.example.test')
    {
        $request = Request::create('https://panel.example.test/api/fixture', 'POST', ['user' => ['id' => $user->id],
            'trade_no' => $order->trade_no, 'method' => $gateway->id, 'token' => $token]);
        $request->headers->set('Origin', $origin);
        Environment::$app->instance('request', $request);
        Environment::$app->instance('url', new class { public function to($path, $params = [], $secure = null) { return 'https://panel.example.test/' . ltrim($path, '/'); } });
        return (new OrderController())->checkout($request)->getData(true);
    }
    private function attempt($order, $gateway)
    {
        return DB::table('v2_payment_attempt')->where('order_id', $order->id)->where('payment_id', $gateway->id)->orderByDesc('id')->first();
    }
    private function notify($gateway, $attempt, $callback, $amount = null)
    {
        $params = ['pid' => $gateway->config['pid'], 'trade_status' => 'TRADE_SUCCESS', 'out_trade_no' => $attempt->trade_no,
            'trade_no' => $callback, 'money' => $amount ?? sprintf('%.2f', $attempt->total_amount / 100)];
        ksort($params); $params['sign'] = md5(stripslashes(urldecode(http_build_query($params))) . $gateway->config['key']); $params['sign_type'] = 'MD5';
        $request = Request::create('/', 'POST', $params); Environment::$app->instance('request', $request);
        return (new PaymentController())->notify('EPay', $gateway->uuid, $request);
    }
    public function testRepeatedCheckoutReusesLinkAndKeepsOriginalReturnRoute()
    {
        $user = $this->user(); $order = $this->order($user); $gateway = $this->gateway();
        $first = $this->checkout($user, $order, $gateway); $again = $this->checkout($user, $order, $gateway);
        $this->assertSame($first, $again); $this->assertSame(1, DB::table('v2_payment_attempt')->count());
        parse_str(parse_url($first['data'], PHP_URL_QUERY), $params);
        $this->assertSame($this->attempt($order, $gateway)->trade_no, $params['out_trade_no']);
        $this->assertNotSame($order->trade_no, $params['out_trade_no']);
        $this->assertSame('https://panel.example.test/#/order/' . $order->trade_no, $params['return_url']);
    }
    public function testEarlierChannelStillSettlesAfterSwitchIncludingItsOriginalFee()
    {
        $user = $this->user(); $order = $this->order($user);
        $a = $this->gateway('EPay', [], ['handling_fee_fixed' => 250]); $b = $this->gateway();
        $this->checkout($user, $order, $a); $first = $this->attempt($order, $a); $this->checkout($user, $order, $b);
        $this->assertSame('success', $this->notify($a, $first, 'paid-before-switch'));
        $this->assertSame((int)$a->id, (int)$order->fresh()->payment_id); $this->assertSame(250, (int)$order->fresh()->handling_amount);
        (new OrderHandleJob($order->trade_no))->handle();
        $this->assertSame(10000, (int)$user->fresh()->balance);
        $this->assertSame('success', $this->notify($a, $first, 'paid-before-switch'));
        $this->assertSame(10000, (int)$user->fresh()->balance);
        Environment::$queue->assertPushed(OrderHandleJob::class, 1);
    }

    public function testChangedReturnConfigurationCreatesNewLinkAndOldAttemptStillSettles()
    {
        $user = $this->user(); $order = $this->order($user); $gateway = $this->gateway();
        $this->checkout($user, $order, $gateway); $old = $this->attempt($order, $gateway);
        config(['v2board.payment_return_url' => 'https://customer.example.test/app',
            'v2board.payment_return_path' => '/#/payment?trade_no={trade_no}']);
        $newLink = $this->checkout($user, $order, $gateway); $new = $this->attempt($order, $gateway);
        $this->assertNotSame($old->id, $new->id);
        parse_str(parse_url($newLink['data'], PHP_URL_QUERY), $params);
        $this->assertSame('https://customer.example.test/app/#/payment?trade_no=' . $order->trade_no, $params['return_url']);
        $this->assertStringContainsString('/api/v1/guest/payment/notify/EPay/', $params['notify_url']);
        $this->assertStringStartsWith('https://panel.example.test/', $params['notify_url']);
        $this->assertSame($newLink, $this->checkout($user, $order, $gateway));
        $this->assertSame(2, DB::table('v2_payment_attempt')->count());
        $this->assertSame('success', $this->notify($gateway, $old, 'before-return-config-change'));
        $this->assertSame(1, (int)$order->fresh()->status);
    }

    public function testChangingOnlyRouteOrAllowedFrontendDoesNotReusePreviousLink()
    {
        $user = $this->user(); $order = $this->order($user); $gateway = $this->gateway();
        $first = $this->checkout($user, $order, $gateway);
        config(['v2board.payment_return_path' => '/#/payment?trade_no={trade_no}']);
        $second = $this->checkout($user, $order, $gateway);
        $this->assertNotSame($first, $second);
        config(['v2board.payment_return_origins' => ['https://second.example.test']]);
        $third = $this->checkout($user, $order, $gateway, null, 'https://second.example.test');
        $this->assertNotSame($second, $third);
        parse_str(parse_url($third['data'], PHP_URL_QUERY), $params);
        $this->assertSame('https://second.example.test/#/payment?trade_no=' . $order->trade_no, $params['return_url']);
        $this->assertSame(3, DB::table('v2_payment_attempt')->count());
    }

    public function testCoinPaymentsKeepsFrontendSubdirectoryOnSuccessAndCancel()
    {
        config(['v2board.payment_return_url' => 'https://customer.example.test/app',
            'v2board.payment_return_path' => '/#/payment?trade_no={trade_no}']);
        $user = $this->user(); $order = $this->order($user);
        $gateway = $this->gateway('CoinPayments', ['coinpayments_merchant_id' => 'merchant',
            'coinpayments_ipn_secret' => 'fixture-secret', 'coinpayments_currency' => 'CNY']);
        $link = $this->checkout($user, $order, $gateway);
        parse_str(parse_url($link['data'], PHP_URL_QUERY), $params);
        $this->assertSame('https://customer.example.test/app/', $params['success_url']);
        $this->assertSame('https://customer.example.test/app/#/payment?trade_no=' . $order->trade_no, $params['cancel_url']);
        $this->assertStringStartsWith('https://panel.example.test/api/v1/guest/payment/notify/', $params['ipn_url']);
    }
    public function testOldCachedLinkCanRefreshWithoutLosingEitherPaymentReference()
    {
        $user = $this->user(); $order = $this->order($user); $gateway = $this->gateway();
        $firstLink = $this->checkout($user, $order, $gateway); $old = $this->attempt($order, $gateway);
        DB::table('v2_payment_attempt')->where('id', $old->id)->update(['updated_at' => time() - 61]);
        $newLink = $this->checkout($user, $order, $gateway); $new = $this->attempt($order, $gateway);
        $this->assertNotSame($firstLink, $newLink); $this->assertNotSame($old->trade_no, $new->trade_no);
        $this->notify($gateway, $old, 'old-link-paid'); $this->notify($gateway, $new, 'new-link-paid');
        (new OrderHandleJob($order->trade_no))->handle();
        $this->assertSame(20000, (int)$user->fresh()->balance); $this->assertSame(2, DB::table('v2_payment_receipt')->count());
        Environment::$queue->assertPushed(OrderHandleJob::class, 1);
    }

    public function testCoinPaymentsHomepageChangeInvalidatesLinkEvenIfOrderUrlIsIdentical()
    {
        config(['v2board.payment_return_url' => 'https://customer.example.test/app',
            'v2board.payment_return_path' => '/orders/{trade_no}']);
        $user = $this->user(); $order = $this->order($user);
        $gateway = $this->gateway('CoinPayments', ['coinpayments_merchant_id' => 'merchant',
            'coinpayments_ipn_secret' => 'fixture-secret', 'coinpayments_currency' => 'CNY']);
        $first = $this->checkout($user, $order, $gateway);
        config(['v2board.payment_return_url' => 'https://customer.example.test/app/orders',
            'v2board.payment_return_path' => '/{trade_no}']);
        $second = $this->checkout($user, $order, $gateway);
        parse_str(parse_url($first['data'], PHP_URL_QUERY), $before);
        parse_str(parse_url($second['data'], PHP_URL_QUERY), $after);
        $this->assertSame($before['cancel_url'], $after['cancel_url']);
        $this->assertNotSame($before['success_url'], $after['success_url']);
        $this->assertNotSame($before['item_number'], $after['item_number']);
        $this->assertSame(2, DB::table('v2_payment_attempt')->count());
    }
    public function testCanceledExternalPaymentGoesToWalletWithoutReusingReturnedBalance()
    {
        $user = $this->user(); $order = $this->order($user, ['total_amount' => 7500, 'balance_amount' => 2500]); $gateway = $this->gateway('EPay', [], ['handling_fee_fixed' => 100]);
        $this->checkout($user, $order, $gateway); $attempt = $this->attempt($order, $gateway);
        $this->assertTrue((new OrderService($order))->cancel());
        $this->assertSame('success', $this->notify($gateway, $attempt, 'late-canceled'));
        $this->assertSame('success', $this->notify($gateway, $attempt, 'late-canceled'));
        $this->assertSame(10100, (int)$user->fresh()->balance); $this->assertSame(2, (int)$order->fresh()->status);
        $adjustment = PaymentAttemptService::adjustments($order->id)[0];
        $this->assertSame(7600, (int)$adjustment->wallet_amount); $this->assertSame('canceled_order', $adjustment->reason);
        Environment::$queue->assertNothingPushed();
    }
    public function testTimedOutExternalPaymentGoesToWalletOnce()
    {
        $user = $this->user(); $order = $this->order($user, ['created_at' => time() - 7201]); $gateway = $this->gateway();
        $this->checkout($user, $order, $gateway); $attempt = $this->attempt($order, $gateway);
        (new OrderHandleJob($order->trade_no))->handle();
        $this->assertSame('success', $this->notify($gateway, $attempt, 'late-timeout'));
        $this->assertSame('success', $this->notify($gateway, $attempt, 'late-timeout'));
        $this->assertSame(10000, (int)$user->fresh()->balance); $this->assertSame(2, (int)$order->fresh()->status);
    }
    public function testTwoActuallyPaidAttemptsCreditSecondAmountOnceWithoutOpeningTwice()
    {
        $user = $this->user(); $order = $this->order($user); $a = $this->gateway(); $b = $this->gateway();
        $this->checkout($user, $order, $a); $first = $this->attempt($order, $a);
        $this->checkout($user, $order, $b); $second = $this->attempt($order, $b);
        $this->notify($a, $first, 'first-paid'); $this->notify($b, $second, 'second-paid'); $this->notify($b, $second, 'second-paid');
        (new OrderHandleJob($order->trade_no))->handle(); (new OrderHandleJob($order->trade_no))->handle();
        $this->assertSame(20000, (int)$user->fresh()->balance); $this->assertSame(3, (int)$order->fresh()->status);
        $this->assertSame(2, DB::table('v2_payment_receipt')->count()); Environment::$queue->assertPushed(OrderHandleJob::class, 1);
    }
    public function testUnderpaymentAndWrongChannelCannotClaimAttemptOrCreditWallet()
    {
        $user = $this->user(); $order = $this->order($user); $gateway = $this->gateway();
        $this->checkout($user, $order, $gateway); $attempt = $this->attempt($order, $gateway);
        try { $this->notify($gateway, $attempt, 'underpaid', '0.01'); $this->fail('Underpayment must be rejected'); }
        catch (HttpException $error) { $this->assertSame(0, DB::table('v2_payment_receipt')->count()); }
        $other = $this->gateway();
        try { $this->notify($other, $attempt, 'wrong-channel'); $this->fail('Wrong channel must be rejected'); }
        catch (HttpException $error) { $this->assertSame(0, (int)$user->fresh()->balance); }
        $this->assertSame(0, (int)$order->fresh()->status); Environment::$queue->assertNothingPushed();
    }
    public function testGeneratingAttemptIsNotReplacedAndFailedRequestCanRetrySameReference()
    {
        $user = $this->user(); $order = $this->order($user); $gateway = $this->gateway(); $service = new PaymentAttemptService();
        $attempt = $service->begin($user->id, $order->trade_no, $gateway->id);
        try { $service->begin($user->id, $order->trade_no, $gateway->id); $this->fail('Concurrent generation should wait'); }
        catch (HttpException $error) { $this->assertSame(409, $error->getStatusCode()); }
        $service->retry($attempt); $again = $service->begin($user->id, $order->trade_no, $gateway->id);
        $this->assertSame($attempt->trade_no, $again->trade_no); $this->assertSame(1, DB::table('v2_payment_attempt')->count());
    }
    public function testManualOpeningRecognizesFirstCallbackInsteadOfGivingFreeWalletCredit()
    {
        $user = $this->user(); $order = $this->order($user); $gateway = $this->gateway();
        $this->checkout($user, $order, $gateway); $attempt = $this->attempt($order, $gateway);
        (new OrderService($order))->paid('manual_operation'); (new OrderHandleJob($order->trade_no))->handle();
        $this->notify($gateway, $attempt, 'manually-verified'); $this->notify($gateway, $attempt, 'manually-verified');
        $this->assertSame(10000, (int)$user->fresh()->balance); $this->assertSame('manually-verified', $order->fresh()->callback_no);
        $this->assertCount(0, PaymentAttemptService::adjustments($order->id));
    }
    public function testReceiptFailureRollsBackWalletAndCanRetry()
    {
        $user = $this->user(); $order = $this->order($user); $gateway = $this->gateway();
        $this->checkout($user, $order, $gateway); $attempt = $this->attempt($order, $gateway); (new OrderService($order))->cancel();
        $schema = DB::connection()->getSchemaBuilder(); $schema->rename('v2_payment_receipt', 'v2_payment_receipt_saved');
        try { $this->notify($gateway, $attempt, 'retry-after-ledger-error'); $this->fail('Missing ledger must reject safely'); }
        catch (HttpException $error) { $this->assertSame(0, (int)$user->fresh()->balance); }
        finally { $schema->rename('v2_payment_receipt_saved', 'v2_payment_receipt'); }
        $this->notify($gateway, $attempt, 'retry-after-ledger-error'); $this->assertSame(10000, (int)$user->fresh()->balance);
    }
    public function testSchemaUpgradeIsRepeatableWithoutChangingAttemptsOrReceipts()
    {
        $user = $this->user(); $order = $this->order($user); $gateway = $this->gateway();
        $this->checkout($user, $order, $gateway); $attempt = $this->attempt($order, $gateway); $this->notify($gateway, $attempt, 'retained');
        (new PaymentLedgerSchema())->ensure(); (new PaymentLedgerSchema())->ensure();
        $this->assertSame(1, DB::table('v2_payment_attempt')->count()); $this->assertSame(1, DB::table('v2_payment_receipt')->count());
    }
    public function testStripeQuoteIsFrozenCachedAndVerifiedInMinorUnits()
    {
        if (!class_exists('Stripe\\Stripe', false)) \Mockery::mock('alias:Stripe\\Stripe')->shouldReceive('setApiKey')->once();
        $captured = null;
        \Mockery::mock('alias:Stripe\\Checkout\\Session')->shouldReceive('create')->once()->andReturnUsing(function ($params, $options) use (&$captured) {
            $captured = [$params, $options]; return (object)['url' => 'https://stripe.example.test/session'];
        });
        PaymentHttpFixture::$responses['https://api.exchangerate-api.com/v4/latest/CNY'] = json_encode(['rates' => ['JPY' => 20]]);
        $user = $this->user(); $order = $this->order($user); $gateway = $this->gateway('StripeCheckout', ['currency' => 'jpy', 'stripe_sk_live' => 'fixture']);
        $first = $this->checkout($user, $order, $gateway); $again = $this->checkout($user, $order, $gateway);
        $this->assertSame($first, $again); $this->assertSame(2000, $captured[0]['line_items'][0]['price_data']['unit_amount']);
        $attempt = $this->attempt($order, $gateway);
        $this->assertStringContainsString($attempt->trade_no, $captured[1]['idempotency_key']);
        $this->assertCount(1, PaymentHttpFixture::$requests);
        $wrong = ['payment_id' => $gateway->id, 'attempt_id' => $attempt->id, 'amount_minor' => 1, 'currency' => 'jpy'];
        $this->assertFalse((new OrderService($order))->paid('stripe-wrong', $wrong));
        $wrong['amount_minor'] = 2000; $wrong['currency'] = 'usd';
        $this->assertFalse((new OrderService($order))->paid('stripe-wrong-currency', $wrong));
        $wrong['currency'] = 'jpy'; $this->assertTrue((new OrderService($order))->paid('stripe-right', $wrong));
        $this->assertSame(1, (int)$order->fresh()->status);
    }
    public function testDisabledChannelStillAcceptsItsExistingAttemptButCannotCreateAnother()
    {
        $user = $this->user(); $order = $this->order($user); $gateway = $this->gateway();
        $this->checkout($user, $order, $gateway); $attempt = $this->attempt($order, $gateway);
        $gateway->update(['enable' => 0]);
        try { $this->checkout($user, $order, $gateway); $this->fail('Disabled channel must reject new checkout'); }
        catch (HttpException $error) { $this->assertSame(422, $error->getStatusCode()); }
        $this->assertSame('success', $this->notify($gateway, $attempt, 'disabled-late'));
        $this->assertSame(1, (int)$order->fresh()->status);
    }
    public function testRotatedConfigUsesEncryptedAttemptVerificationSnapshot()
    {
        $user = $this->user(); $order = $this->order($user); $gateway = $this->gateway();
        $this->checkout($user, $order, $gateway); $attempt = $this->attempt($order, $gateway);
        $this->assertStringNotContainsString('fixture-secret', $attempt->config_snapshot);
        DB::table('v2_payment')->where('id', $gateway->id)->update(['config' => json_encode(['pid' => 'new-merchant', 'key' => 'new-key', 'url' => 'https://new.example.test'])]);
        $this->assertSame('success', $this->notify($gateway, $attempt, 'old-config-late'));
        $this->assertSame('success', $this->notify($gateway, $attempt, 'old-config-late'));
        $this->assertSame(1, DB::table('v2_payment_receipt')->count());
    }
    public function testCouponCancellationLatePaymentAndReplayReleaseOnlyOnce()
    {
        $user = $this->user(); $gateway = $this->gateway();
        $coupon = \App\Models\Coupon::create(['code' => 'held', 'value' => 1000, 'type' => 1, 'limit_use' => 0, 'started_at' => time() - 1, 'ended_at' => time() + 100]);
        $order = $this->order($user, ['total_amount' => 7500, 'balance_amount' => 2500, 'coupon_id' => $coupon->id]);
        $this->checkout($user, $order, $gateway); $attempt = $this->attempt($order, $gateway);
        $service = new OrderService($order); $this->assertTrue($service->cancel()); $this->assertFalse($service->cancel());
        $this->notify($gateway, $attempt, 'late-with-coupon'); $this->notify($gateway, $attempt, 'late-with-coupon');
        $this->assertSame(1, (int)$coupon->fresh()->limit_use); $this->assertSame(10000, (int)$user->fresh()->balance);
        $this->assertSame(1, DB::table('v2_payment_receipt')->count()); Environment::$queue->assertNothingPushed();
    }
    public function testOldKeyCannotAuthenticateNewAttemptAfterRotation()
    {
        $user = $this->user(); $order = $this->order($user); $gateway = $this->gateway();
        $this->checkout($user, $order, $gateway); $old = $this->attempt($order, $gateway);
        $newGateway = Payment::find($gateway->id); $newGateway->config = ['pid' => 'new-merchant', 'key' => 'new-key', 'url' => 'https://new.example.test']; $newGateway->save();
        $this->checkout($user, $order, $newGateway); $new = $this->attempt($order, $gateway);
        $this->assertNotSame($old->id, $new->id);
        try { $this->notify($gateway, $new, 'old-key-new-attempt'); $this->fail('Old key must not authorize new config attempts'); }
        catch (HttpException $error) { $this->assertSame(0, DB::table('v2_payment_receipt')->count()); }
        $this->assertSame('success', $this->notify($newGateway, $old, 'provider-rotated-key-old-attempt'));
    }
    public function testBtcpayReferenceLookupStillAcceptsOldSnapshotAfterRotationAndDisable()
    {
        $user = $this->user(); $order = $this->order($user);
        $config = ['btcpay_url' => 'https://fixture.test/', 'btcpay_storeId' => 'store-1', 'btcpay_api_key' => 'old-api', 'btcpay_webhook_key' => 'old-webhook'];
        $gateway = $this->gateway('BTCPay', $config);
        $attempt = (new PaymentAttemptService())->begin($user->id, $order->trade_no, $gateway->id);
        $gateway->config = array_merge($config, ['btcpay_webhook_key' => 'new-webhook']); $gateway->enable = 0; $gateway->save();
        PaymentHttpFixture::$responses['https://fixture.test/api/v1/stores/store-1/invoices/invoice-1'] = json_encode([
            'status' => 'Settled', 'metadata' => ['orderId' => $attempt->trade_no], 'amount' => '100.00', 'currency' => 'CNY']);
        $body = json_encode(['type' => 'InvoiceSettled', 'invoiceId' => 'invoice-1']);
        $request = Request::create('/', 'POST', [], [], [], [], $body);
        $request->headers->set('BTCPay-Sig', 'sha256=' . hash_hmac('sha256', $body, 'old-webhook'));
        Environment::$app->instance('request', $request);
        $this->assertSame('success', (new PaymentController())->notify('BTCPay', $gateway->uuid, $request));
        $this->assertSame(1, (int)$order->fresh()->status);
    }
}
