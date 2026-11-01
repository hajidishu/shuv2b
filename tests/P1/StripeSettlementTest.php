<?php
namespace Tests\P1;

use App\Models\Payment;
use App\Services\OrderService;
use App\Services\PaymentAttemptService;
use App\Services\PaymentSettlementService;
use Illuminate\Http\Request;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Regression coverage uses only synthetic signed events and local ledger rows. */
class StripeSettlementTest extends DatabaseTestCase
{
    private const WEBHOOK_SECRET = 'whsec_fixture_only_not_a_real_secret';

    private function gateway(string $method, array $config = []): Payment
    {
        return Payment::create(['uuid' => uniqid('stripe-f02-'), 'payment' => $method, 'enable' => 1,
            'config' => $config + ['currency' => 'usd', 'stripe_sk_live' => 'sk_test_fixture',
                'stripe_webhook_key' => self::WEBHOOK_SECRET]]);
    }

    private function attempt($user, $order, $gateway, int $minor = 12500, string $currency = 'usd')
    {
        $attempt = (new PaymentAttemptService())->begin($user->id, $order->trade_no, $gateway->id);
        DB::table('v2_payment_attempt')->where('id', $attempt->id)->update([
            'gateway_amount_minor' => $minor, 'gateway_currency' => $currency,
            'status' => 'pending', 'lease_token' => null, 'lease_at' => null,
        ]);
        return DB::table('v2_payment_attempt')->where('id', $attempt->id)->first();
    }

    private function signedNotify($gateway, array $object, string $type = 'payment_intent.succeeded')
    {
        $time = time();
        $body = json_encode(['id' => 'evt_fixture_' . bin2hex(random_bytes(4)), 'object' => 'event',
            'api_version' => '2020-08-27', 'created' => $time, 'livemode' => false, 'pending_webhooks' => 1,
            'request' => null, 'type' => $type, 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
        $signature = 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $body, self::WEBHOOK_SECRET);
        $request = Request::create('/', 'POST', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature], $body);
        Environment::$app->instance('request', $request);
        $_SERVER['HTTP_STRIPE_SIGNATURE'] = $signature;
        return (new \App\Http\Controllers\V1\Guest\PaymentController())->notify($gateway->payment, $gateway->uuid, $request);
    }

    private function intent($reference, int $amount = 12500, string $currency = 'usd'): array
    {
        return ['id' => 'pi_fixture_' . bin2hex(random_bytes(4)), 'object' => 'payment_intent',
            'status' => 'succeeded', 'amount' => $amount, 'amount_received' => $amount,
            'currency' => $currency, 'metadata' => ['out_trade_no' => $reference]];
    }

    private function notifyObject($method, $reference): array
    {
        switch ($method) {
            case 'StripeCheckout':
                return [$this->signedNotifyShape(['id' => 'cs_fixture_' . bin2hex(random_bytes(3)),
                    'object' => 'checkout.session', 'payment_status' => 'paid', 'client_reference_id' => $reference,
                    'payment_intent' => 'pi_fixture_' . bin2hex(random_bytes(3)), 'amount_total' => 12500, 'currency' => 'usd'], 'checkout.session.completed')];
            case 'StripeCredit':
            case 'StripeAlipay':
            case 'StripeWepay':
                return [$this->signedNotifyShape(['id' => 'ch_fixture_' . bin2hex(random_bytes(3)), 'object' => 'charge',
                    'status' => 'succeeded', 'amount' => 12500, 'currency' => 'usd',
                    'metadata' => ['out_trade_no' => $reference]], 'charge.succeeded')];
            default:
                return [$this->signedNotifyShape($this->intent($reference), 'payment_intent.succeeded')];
        }
    }

    private function signedNotifyShape(array $object, string $type)
    {
        // Keep fixture generation in signedNotify's real Stripe webhook verifier path.
        return ['object' => $object, 'type' => $type];
    }

    private function dispatch($gateway, array $shape)
    {
        return $this->signedNotify($gateway, $shape['object'], $shape['type']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testEveryStripeAdapterRejectsAnOrderTradeNoAndSettlesOnlyItsFrozenAttemptQuote()
    {
        foreach (['StripeALL', 'StripeCredit', 'StripeCheckout', 'StripeAlipay', 'StripeWepay'] as $method) {
            $user = $this->user(); $order = $this->order($user, ['total_amount' => 12000]);
            $gateway = $this->gateway($method); $attempt = $this->attempt($user, $order, $gateway, 12500, 'usd');

            try {
                $this->dispatch($gateway, $this->notifyObject($method, $order->trade_no)[0]);
                $this->fail($method . ' accepted the original order reference');
            } catch (HttpException $e) {
                $this->assertSame(0, (int)$order->fresh()->status, $method . ' must not settle the original order reference');
            }

            $this->assertSame('success', $this->dispatch($gateway, $this->notifyObject($method, $attempt->trade_no)[0]));
            $this->assertSame(1, (int)$order->fresh()->status);
            $this->assertSame($gateway->id, (int)$order->fresh()->payment_id);
        }
    }

    public function testMissingOrMismatchedFrozenQuoteCannotSettle()
    {
        $user = $this->user(); $order = $this->order($user, ['total_amount' => 12000]);
        $gateway = $this->gateway('StripeALL'); $attempt = $this->attempt($user, $order, $gateway);
        $payment = ['payment_id' => $gateway->id, 'attempt_id' => $attempt->id];
        $this->assertFalse((new OrderService($order))->paid('pi_no_quote', $payment));
        $payment['amount_minor'] = 12500; $payment['currency'] = 'eur';
        $this->assertFalse((new OrderService($order))->paid('pi_wrong_quote', $payment));
        $this->assertSame(0, (int)$order->fresh()->status);

        DB::table('v2_payment_attempt')->where('id', $attempt->id)->update(['gateway_amount_minor' => null, 'gateway_currency' => null]);
        $payment['currency'] = 'usd';
        $this->assertFalse((new OrderService($order))->paid('pi_unfrozen', $payment));
    }

    public function testStripeCannotSettleWithShortAmountOrWithoutRecordedAttempt()
    {
        $user = $this->user(); $order = $this->order($user, ['total_amount' => 12000]);
        $gateway = $this->gateway('StripeALL'); $attempt = $this->attempt($user, $order, $gateway, 12500, 'usd');
        $underpaid = ['payment_id' => $gateway->id, 'attempt_id' => $attempt->id,
            'amount_minor' => 12499, 'currency' => 'usd'];
        $this->assertFalse((new OrderService($order))->paid('pi_short', $underpaid));
        $this->assertSame(0, (int)$order->fresh()->status);

        $order->payment_id = $gateway->id; $order->save();
        $untracked = ['trade_no' => $order->trade_no, 'payment_id' => $gateway->id,
            'amount_minor' => 12500, 'currency' => 'usd'];
        $this->assertNull(PaymentAttemptService::resolve($untracked));
        $this->assertFalse((new PaymentSettlementService())->settle($order, 'pi_no_attempt', $untracked));
        $this->assertSame(0, (int)$order->fresh()->status);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testEarlyStripeCallbackUsesFrozenAttemptAfterRotationAndCompleteKeepsPaid()
    {
        $user = $this->user(); $order = $this->order($user, ['total_amount' => 12000]);
        $gateway = $this->gateway('StripeALL', ['currency' => 'jpy']);
        $attempt = (new PaymentAttemptService())->begin($user->id, $order->trade_no, $gateway->id);
        DB::table('v2_payment_attempt')->where('id', $attempt->id)->update([
            'gateway_amount_minor' => 1200, 'gateway_currency' => 'jpy', 'status' => 'creating',
        ]);
        $attempt = DB::table('v2_payment_attempt')->where('id', $attempt->id)->first();
        $this->assertNotNull($attempt->lease_token, 'Creating attempt retains its checkout lease');

        $gateway->config = ['currency' => 'eur', 'stripe_sk_live' => 'sk_test_rotated',
            'stripe_webhook_key' => 'whsec_rotated_fixture_only'];
        $gateway->enable = 0; $gateway->save();
        $this->assertSame('success', $this->signedNotify($gateway, $this->intent($attempt->trade_no, 1200, 'jpy')));
        $this->assertSame(1, (int)$order->fresh()->status);
        $this->assertSame($gateway->id, (int)$order->fresh()->payment_id);
        $this->assertSame(1200, (int)DB::table('v2_payment_attempt')->where('id', $attempt->id)->value('gateway_amount_minor'));
        $this->assertSame('paid', DB::table('v2_payment_attempt')->where('id', $attempt->id)->value('status'));

        (new PaymentAttemptService())->complete($attempt, ['type' => 1, 'data' => 'https://fixture.test/checkout']);
        $stored = DB::table('v2_payment_attempt')->where('id', $attempt->id)->first();
        $this->assertSame('paid', $stored->status, 'Late checkout completion must not downgrade paid attempt');
        $this->assertSame(1, DB::table('v2_payment_receipt')->count());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDuplicateSettlementAndJobsDoNotExtendSubscriptionTwice()
    {
        $plan = \App\Models\Plan::create(['month_price' => 12000]);
        $expiry = strtotime('+4 days');
        $user = $this->user(['plan_id' => $plan->id, 'expired_at' => $expiry]);
        $order = $this->order($user, ['total_amount' => 12000, 'type' => 2, 'plan_id' => $plan->id, 'period' => 'month_price']);
        $gateway = $this->gateway('StripeALL'); $attempt = $this->attempt($user, $order, $gateway);
        $event = $this->intent($attempt->trade_no);
        $this->assertSame('success', $this->signedNotify($gateway, $event));
        $this->assertSame('success', $this->signedNotify($gateway, $event));
        (new \App\Jobs\OrderHandleJob($order->trade_no))->handle();
        (new \App\Jobs\OrderHandleJob($order->trade_no))->handle();
        $this->assertSame(strtotime('+1 month', $expiry), (int)$user->fresh()->expired_at);
        $this->assertSame(1, DB::table('v2_payment_receipt')->count());
        $this->assertSame('paid', DB::table('v2_payment_attempt')->where('id', $attempt->id)->value('status'));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDispatchFailureKeepsPaymentAndCheckOrderRequeuesARealOpenJob()
    {
        $plan = \App\Models\Plan::create(['month_price' => 12000]);
        $user = $this->user(); $order = $this->order($user, ['total_amount' => 12000, 'type' => 2,
            'plan_id' => $plan->id, 'period' => 'month_price']);
        $gateway = $this->gateway('StripeALL'); $attempt = $this->attempt($user, $order, $gateway);
        $realDispatcher = Environment::$app->make(BusDispatcher::class);
        $failingDispatcher = \Mockery::mock(BusDispatcher::class);
        $failingDispatcher->shouldReceive('dispatch')->andThrow(new \RuntimeException('injected queue outage'));
        Environment::$app->instance(BusDispatcher::class, $failingDispatcher);
        try {
            $this->assertSame('success', $this->signedNotify($gateway, $this->intent($attempt->trade_no)));
        } finally {
            Environment::$app->instance(BusDispatcher::class, $realDispatcher);
        }
        $this->assertSame(1, (int)$order->fresh()->status, 'Settlement must persist as paid despite dispatch failure');
        Environment::$queue->assertNothingPushed();

        (new \App\Console\Commands\CheckOrder())->handle();
        Environment::$queue->assertPushed(\App\Jobs\OrderHandleJob::class, 1);
        Environment::$queue->pushed(\App\Jobs\OrderHandleJob::class)->last()->handle();
        $this->assertSame(3, (int)$order->fresh()->status);
        $this->assertSame($plan->id, (int)$user->fresh()->plan_id);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFailedOpeningTransactionCanRetryWithoutPartialEntitlement()
    {
        $plan = \App\Models\Plan::create(['month_price' => 12000]);
        $expiry = strtotime('+2 days');
        $user = $this->user(['plan_id' => $plan->id, 'expired_at' => $expiry]);
        $order = $this->order($user, ['total_amount' => 12000, 'type' => 2, 'plan_id' => $plan->id, 'period' => 'month_price']);
        $gateway = $this->gateway('StripeALL'); $attempt = $this->attempt($user, $order, $gateway);
        $this->assertSame('success', $this->signedNotify($gateway, $this->intent($attempt->trade_no)));
        $job = Environment::$queue->pushed(\App\Jobs\OrderHandleJob::class)->last();
        $failOnce = true;
        \App\Models\Order::saving(function ($model) use ($order, &$failOnce) {
            if ($failOnce && $model->id === $order->id && (int)$model->status === 3) {
                $failOnce = false;
                throw new \RuntimeException('injected entitlement write failure');
            }
        });
        try { $job->handle(); $this->fail('Opening failure should bubble to the job retry mechanism'); }
        catch (\RuntimeException $error) { $this->assertSame('injected entitlement write failure', $error->getMessage()); }
        $this->assertSame(1, (int)$order->fresh()->status);
        $this->assertSame($expiry, (int)$user->fresh()->expired_at);
        $this->assertSame(0, DB::transactionLevel());
        $job->handle();
        $this->assertSame(3, (int)$order->fresh()->status);
        $this->assertSame(strtotime('+1 month', $expiry), (int)$user->fresh()->expired_at);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTelegramQueueDispatchFailureDoesNotTurnPaidCallbackIntoFailure()
    {
        config(['v2board.telegram_bot_enable' => 1]);
        $user = $this->user(['telegram_admin_id' => 998877, 'is_admin' => 1]);
        $order = $this->order($user, ['total_amount' => 12000]);
        $gateway = $this->gateway('StripeALL'); $attempt = $this->attempt($user, $order, $gateway);
        $realDispatcher = Environment::$app->make(BusDispatcher::class);
        $sendTelegramCalls = 0;
        $selectiveDispatcher = \Mockery::mock(BusDispatcher::class);
        $selectiveDispatcher->shouldReceive('dispatch')->atLeast()->once()->andReturnUsing(function ($job) use ($realDispatcher, &$sendTelegramCalls) {
            if ($job instanceof \App\Jobs\SendTelegramJob) {
                ++$sendTelegramCalls;
                throw new \RuntimeException('injected Telegram queue outage');
            }
            return $realDispatcher->dispatch($job);
        });
        Environment::$app->instance(BusDispatcher::class, $selectiveDispatcher);
        try {
            $this->assertSame('success', $this->signedNotify($gateway, $this->intent($attempt->trade_no)));
        } finally {
            Environment::$app->instance(BusDispatcher::class, $realDispatcher);
        }
        $this->assertSame(1, (int)$order->fresh()->status);
        $this->assertSame(1, DB::table('v2_payment_receipt')->count());
        $this->assertSame(1, $sendTelegramCalls, 'The Telegram queue failure path must execute');
        Environment::$queue->assertPushed(\App\Jobs\OrderHandleJob::class, 1);
    }

    public function testSourceAttemptValidatorRequiresRecordedAdapterAndQuote()
    {
        $user = $this->user(); $order = $this->order($user); $gateway = $this->gateway('StripeAlipay');
        $attempt = $this->attempt($user, $order, $gateway, 12500, 'usd');
        $source = (object)['metadata' => (object)['out_trade_no' => $attempt->trade_no], 'amount' => 12500, 'currency' => 'usd'];
        $this->assertSame($attempt->id, PaymentAttemptService::stripeSourceAttempt($source, 'StripeAlipay', $gateway->id)->id);
        foreach ([['StripeWepay', $gateway->id], ['StripeAlipay', $gateway->id + 100]] as [$method, $id]) {
            try { PaymentAttemptService::stripeSourceAttempt($source, $method, $id); $this->fail('Mismatched source attempt accepted'); }
            catch (HttpException $e) { $this->assertSame(0, (int)$order->fresh()->status); }
        }
        $source->amount = 1;
        try { PaymentAttemptService::stripeSourceAttempt($source, 'StripeAlipay', $gateway->id); $this->fail('Mismatched Source amount accepted'); }
        catch (HttpException $e) { $this->assertSame(0, (int)$order->fresh()->status); }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAlipayAndWepayValidateSourceBeforeChargeAndChargeFromLocalQuote()
    {
        $http = new class implements \Stripe\HttpClient\ClientInterface {
            public $requests = [];
            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->requests[] = compact('method', 'absUrl', 'headers', 'params', 'hasFile', 'apiMode');
                return [json_encode(['id' => 'ch_fixture', 'object' => 'charge', 'paid' => true, 'status' => 'succeeded',
                    'amount' => 12500, 'currency' => 'usd', 'created' => time(), 'livemode' => false,
                    'source' => $params['source'], 'metadata' => $params['metadata']]), 200,
                    ['request-id' => 'req_fixture_charge']];
            }
        };
        \Stripe\ApiRequestor::setHttpClient($http);

        // StripeCredit must acknowledge the event without attempting a second charge.
        $creditUser = $this->user(); $creditOrder = $this->order($creditUser);
        $creditGateway = $this->gateway('StripeCredit'); $creditAttempt = $this->attempt($creditUser, $creditOrder, $creditGateway);
        $beforeCreditEvent = count($http->requests);
        $this->assertSame('success', $this->signedNotify($creditGateway, ['id' => 'src_credit_fixture', 'object' => 'source',
            'amount' => 12500, 'currency' => 'usd', 'metadata' => ['out_trade_no' => $creditAttempt->trade_no]], 'source.chargeable'));
        $this->assertCount($beforeCreditEvent, $http->requests, 'StripeCredit must not charge source.chargeable events');

        foreach (['StripeAlipay', 'StripeWepay'] as $method) {
            $user = $this->user(); $order = $this->order($user, ['total_amount' => 12000]);
            $gateway = $this->gateway($method); $attempt = $this->attempt($user, $order, $gateway, 12500, 'usd');
            $baseCalls = count($http->requests);
            $badSource = ['id' => 'src_bad_' . bin2hex(random_bytes(3)), 'object' => 'source',
                'amount' => 1, 'currency' => 'usd', 'metadata' => ['out_trade_no' => $attempt->trade_no, 'user_id' => 999999]];
            try {
                $this->signedNotify($gateway, $badSource, 'source.chargeable');
                $this->fail($method . ' must reject a mismatched Source before charging');
            } catch (HttpException $e) {
                $this->assertSame(0, (int)$order->fresh()->status);
            }
            $this->assertSame($baseCalls, count($http->requests), 'Invalid Source must not issue a Stripe Charge request');

            $sourceId = 'src_good_' . bin2hex(random_bytes(3));
            $goodSource = ['id' => $sourceId, 'object' => 'source', 'amount' => 12500, 'currency' => 'usd',
                'metadata' => ['out_trade_no' => $attempt->trade_no, 'user_id' => 999999]];
            $this->assertSame('success', $this->signedNotify($gateway, $goodSource, 'source.chargeable'));
            $this->assertCount($baseCalls + 1, $http->requests);
            $call = $http->requests[$baseCalls];
            $this->assertSame('post', $call['method']);
            $this->assertStringContainsString('/v1/charges', $call['absUrl']);
            $this->assertSame(12500, (int)$call['params']['amount']);
            $this->assertSame('usd', $call['params']['currency']);
            $this->assertSame($sourceId, $call['params']['source']);
            $this->assertSame($attempt->trade_no, $call['params']['metadata']['out_trade_no']);
            $this->assertSame((int)$attempt->user_id, (int)$call['params']['metadata']['user_id']);
            $this->assertNotSame(999999, (int)$call['params']['metadata']['user_id']);
            $this->assertTrue((bool)array_filter($call['headers'], function ($header) use ($sourceId) {
                return stripos($header, 'Idempotency-Key: stripe-source-charge:' . $sourceId) === 0;
            }), 'Charge request must retain the source idempotency key');
        }
    }
}
