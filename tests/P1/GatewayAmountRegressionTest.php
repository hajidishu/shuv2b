<?php
namespace Tests\P1;

use App\Http\Controllers\V1\Guest\PaymentController;
use App\Jobs\OrderHandleJob;
use App\Models\Plan;
use App\Models\Payment;
use App\Services\OrderService;
use App\Services\PaymentAttemptService;
use App\Services\PaymentSettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class GatewayAmountRegressionTest extends DatabaseTestCase
{
    private function gateway($method, $fee = 0)
    {
        return Payment::create(['uuid' => uniqid('amount-'), 'payment' => $method, 'enable' => 1,
            'handling_fee_fixed' => $fee,
            'config' => ['pid' => 'fixture-app', 'key' => 'fixture-secret', 'url' => 'https://gateway.example.test']]);
    }

    private function signedNotify($gateway, $attempt, $callback, array $overrides = [])
    {
        $params = array_merge(['pid' => 'fixture-app', 'out_trade_no' => $attempt->trade_no,
            'trade_no' => $callback, 'trade_status' => 'TRADE_SUCCESS',
            'money' => number_format($attempt->total_amount / 100, 2, '.', ''), 'sign_type' => 'MD5'], $overrides);
        $omitMoney = !empty($params['__omit_money']);
        unset($params['__omit_money']);
        if ($omitMoney) unset($params['money']);
        unset($params['sign']);
        $signParams = $params;
        unset($signParams['sign_type']);
        $signParams = array_filter($signParams, static function ($value) { return $value !== '' && $value !== null; });
        ksort($signParams);
        $pairs = [];
        foreach ($signParams as $key => $value) $pairs[] = $key . '=' . $value;
        $params['sign'] = md5(implode('&', $pairs) . 'fixture-secret');
        $request = Request::create('/', 'POST', $params);
        Environment::$app->instance('request', $request);
        return (new PaymentController())->notify($gateway->payment, $gateway->uuid, $request);
    }

    public function testBothGatewaysRejectMissingOrMismatchedSignedAmountsAndSettleFrozenQuoteOnce()
    {
        foreach (['EPayQrcode', 'Paytaro'] as $index => $method) {
            $user = $this->user();
            $order = $this->order($user);
            $gateway = $this->gateway($method, 275);
            $attempt = (new PaymentAttemptService())->begin($user->id, $order->trade_no, $gateway->id);
            $this->assertSame(10275, (int)$attempt->total_amount, $method . ' freezes base plus fee');

            foreach ([
                ['missing-money', ['__omit_money' => true]],
                ['underpaid', ['money' => '100.00']],
                ['overpaid', ['money' => '102.76']],
                ['wrong-currency', ['currency' => 'USD']],
            ] as $case) {
                $overrides = $case[1];
                $receiptsBefore = DB::table('v2_payment_receipt')->count();
                try {
                    $this->signedNotify($gateway, $attempt, $method . '-' . $case[0], $overrides);
                    $this->fail($method . ' accepted ' . $case[0]);
                } catch (HttpException $error) {
                    $this->assertSame($receiptsBefore, DB::table('v2_payment_receipt')->count());
                    $this->assertSame(0, (int)$order->fresh()->status);
                }
            }

            $this->assertSame('success', $this->signedNotify($gateway, $attempt, $method . '-paid'));
            $this->assertSame('success', $this->signedNotify($gateway, $attempt, $method . '-paid'));
            $this->assertSame(1, (int)$order->fresh()->status);
            $this->assertSame(275, (int)$order->fresh()->handling_amount);
            $this->assertSame($index + 1, DB::table('v2_payment_receipt')->count());
            (new OrderHandleJob($order->trade_no))->handle();
            (new OrderHandleJob($order->trade_no))->handle();
            $this->assertSame(10000, (int)$user->fresh()->balance);
            Environment::$queue->assertPushed(OrderHandleJob::class, $index + 1);
        }
    }

    public function testBothGatewaysOpenMonthlyPlanOnceDespiteRepeatedCallbackAndWorker()
    {
        foreach (['EPayQrcode', 'Paytaro'] as $method) {
            $user = $this->user(['u' => 2147483648, 'd' => 3221225472]);
            $plan = Plan::create(['name' => 'Monthly fixture', 'show' => 1, 'renew' => 1, 'group_id' => 7,
                'transfer_enable' => 50, 'device_limit' => 4, 'speed_limit' => 25, 'month_price' => 12300]);
            $order = $this->order($user, ['plan_id' => $plan->id, 'period' => 'month_price', 'type' => 1,
                'total_amount' => $plan->month_price]);
            $gateway = $this->gateway($method, 225);
            $attempt = (new PaymentAttemptService())->begin($user->id, $order->trade_no, $gateway->id);
            $this->assertSame(12525, (int)$attempt->total_amount);

            $this->assertSame('success', $this->signedNotify($gateway, $attempt, $method . '-plan-paid'));
            $this->assertSame('success', $this->signedNotify($gateway, $attempt, $method . '-plan-paid'));
            (new OrderHandleJob($order->trade_no))->handle();
            $opened = $user->fresh();
            $this->assertSame($plan->id, (int)$opened->plan_id);
            $this->assertSame(7, (int)$opened->group_id);
            $this->assertSame(4, (int)$opened->device_limit);
            $this->assertSame(25, (int)$opened->speed_limit);
            $this->assertSame(50 * 1073741824, (int)$opened->transfer_enable);
            $this->assertSame(0, (int)$opened->u);
            $this->assertSame(0, (int)$opened->d);
            $expiresAt = (int)$opened->expired_at;
            $this->assertGreaterThan(strtotime('+1 month', time()) - 5, $expiresAt);
            $this->assertLessThan(strtotime('+1 month', time()) + 5, $expiresAt);
            $this->assertSame(3, (int)$order->fresh()->status);

            (new OrderHandleJob($order->trade_no))->handle();
            $this->assertSame('success', $this->signedNotify($gateway, $attempt, $method . '-plan-paid'));
            $this->assertSame($expiresAt, (int)$user->fresh()->expired_at, 'Duplicate callbacks/jobs must not extend expiry');
            $this->assertSame(1, DB::table('v2_payment_receipt')->where('attempt_id', $attempt->id)->count());
            Environment::$queue->assertPushed(OrderHandleJob::class, ($method === 'EPayQrcode' ? 1 : 2));
        }
    }

    public function testOldGatewayAttemptSettlesAfterChannelSwitchWithItsFrozenFee()
    {
        $user = $this->user(); $order = $this->order($user);
        $old = $this->gateway('Paytaro', 350);
        $attempt = (new PaymentAttemptService())->begin($user->id, $order->trade_no, $old->id);
        $new = $this->gateway('EPayQrcode', 900);
        $newAttempt = (new PaymentAttemptService())->begin($user->id, $order->trade_no, $new->id);
        $this->assertNotSame($attempt->id, $newAttempt->id);
        $this->assertSame('success', $this->signedNotify($old, $attempt, 'switch-paid'));
        $this->assertSame($old->id, (int)$order->fresh()->payment_id);
        $this->assertSame(350, (int)$order->fresh()->handling_amount);
        (new OrderHandleJob($order->trade_no))->handle();
        $this->assertSame(10000, (int)$user->fresh()->balance);
    }

    /** @dataProvider invalidGatewayAmounts */
    public function testAdaptersRejectInvalidSignedAmountValues($amount)
    {
        foreach (['EPayQrcode', 'Paytaro'] as $method) {
            $params = ['pid' => 'fixture-app', 'out_trade_no' => 'order-amount', 'trade_no' => 'callback-amount',
                'trade_status' => 'TRADE_SUCCESS', 'money' => $amount, 'sign_type' => 'MD5'];
            if (is_array($amount)) {
                $params['sign'] = str_repeat('a', 32); // Both adapters reject nested values before signature parsing.
            } else {
                $signParams = $params;
                unset($signParams['sign_type']);
                $signParams = array_filter($signParams, static function ($value) { return $value !== '' && $value !== null; });
                ksort($signParams);
                $pairs = [];
                foreach ($signParams as $key => $value) $pairs[] = $key . '=' . $value;
                $params['sign'] = md5(implode('&', $pairs) . 'fixture-secret');
            }
            $class = 'App\\Payments\\' . $method;
            $adapter = new $class(['pid' => 'fixture-app', 'key' => 'fixture-secret']);
            $this->assertFalse($adapter->notify($params), $method . ' accepted invalid amount ' . (is_array($amount) ? 'array' : (string)$amount));
        }
    }

    public static function invalidGatewayAmounts()
    {
        return [[0], ['0.00'], ['-0.01'], ['1.001'], [['100.00']]];
    }

    public function testSettlementRejectsMissingAmountEvenWhenAttemptAndPaymentMatch()
    {
        $user = $this->user(); $order = $this->order($user);
        $gateway = $this->gateway('EPayQrcode');
        $attempt = (new PaymentAttemptService())->begin($user->id, $order->trade_no, $gateway->id);
        $this->assertFalse((new PaymentSettlementService())->settle($order, 'missing-amount-settlement', [
            'payment_id' => $gateway->id, 'attempt_id' => $attempt->id,
        ]));
        $this->assertSame(0, DB::table('v2_payment_receipt')->count());
        $this->assertSame(0, (int)$order->fresh()->status);
    }

    public function testLatePaidCanceledOrderCreditsFrozenGrossToWalletOnlyOnce()
    {
        $user = $this->user(); $order = $this->order($user, ['total_amount' => 7500, 'balance_amount' => 2500]);
        $gateway = $this->gateway('EPayQrcode', 125);
        $attempt = (new PaymentAttemptService())->begin($user->id, $order->trade_no, $gateway->id);
        $this->assertTrue((new OrderService($order))->cancel());
        $this->assertSame('success', $this->signedNotify($gateway, $attempt, 'canceled-paid'));
        $this->assertSame('success', $this->signedNotify($gateway, $attempt, 'canceled-paid'));
        $this->assertSame(10125, (int)$user->fresh()->balance);
        $this->assertSame(2, (int)$order->fresh()->status);
        $adjustment = PaymentAttemptService::adjustments($order->id)[0];
        $this->assertSame(7625, (int)$adjustment->wallet_amount);
        $this->assertSame('canceled_order', $adjustment->reason);
        Environment::$queue->assertNothingPushed();
    }
}
