<?php

namespace Tests\P1;

use App\Http\Controllers\V1\Guest\PaymentController;
use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\EPay;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EpayTest extends DatabaseTestCase
{
    private function payment(array $config = [])
    {
        return Payment::create(['uuid' => uniqid('epay-'), 'payment' => 'EPay', 'config' => array_merge(['pid' => 'merchant-1', 'key' => 'secret', 'url' => 'https://example.test'], $config)]);
    }

    private function receipt($payment, $order, array $overrides = [])
    {
        $params = array_merge(['pid' => $payment->config['pid'], 'trade_status' => 'TRADE_SUCCESS', 'out_trade_no' => $order->trade_no, 'trade_no' => 'receipt-1', 'money' => '100.00'], $overrides);
        $signed = $params;
        unset($signed['sign'], $signed['sign_type']);
        ksort($signed);
        $params['sign'] = md5(stripslashes(urldecode(http_build_query($signed))) . $payment->config['key']);
        $params['sign_type'] = $overrides['sign_type'] ?? 'MD5';
        return Request::create('/', 'POST', $params);
    }

    private function notify($payment, $request)
    {
        Environment::$app->instance('request', $request);
        return (new PaymentController())->notify('EPay', $payment->uuid, $request);
    }

    public function invalidAmounts()
    {
        return [['0.01'], ['0'], ['-1'], ['1e2'], ['100.001'], ['100.000'], ['NaN'], ['INF'], [true], [[]], ['999999999999999999999']];
    }

    /** @dataProvider invalidAmounts */
    public function testValidSignatureCannotBypassAmountValidation($amount)
    {
        $payment = $this->payment();
        $user = $this->user();
        $order = $this->order($user, ['payment_id' => $payment->id]);
        try {
            $this->notify($payment, $this->receipt($payment, $order, ['money' => $amount]));
            $this->fail('Invalid amount must not settle');
        } catch (HttpException $e) {
            $this->assertSame(0, (int)$order->fresh()->status);
            $this->assertSame(0, (int)$user->fresh()->balance);
            Environment::$queue->assertNothingPushed();
        }
    }

    public function invalidMetadata()
    {
        return [[['pid' => 'another-merchant']], [['pid' => null]], [['sign_type' => 'SHA256']], [['trade_no' => '']], [['out_trade_no' => '']], [['currency' => 'USD']]];
    }

    /** @dataProvider invalidMetadata */
    public function testValidSignatureCannotBypassMerchantAndMetadataBinding($overrides)
    {
        $payment = $this->payment();
        $order = $this->order($this->user(), ['payment_id' => $payment->id]);
        try {
            $this->notify($payment, $this->receipt($payment, $order, $overrides));
            $this->fail('Invalid metadata must not settle');
        } catch (HttpException $e) {
            $this->assertSame(0, (int)$order->fresh()->status);
        }
    }

    public function testSuccessfulReceiptIncludesFeeAndIsIdempotent()
    {
        $payment = $this->payment();
        $user = $this->user();
        $order = $this->order($user, ['payment_id' => $payment->id, 'handling_amount' => 250]);
        $r = $this->receipt($payment, $order, ['money' => '102.50']);
        $this->assertSame('success', $this->notify($payment, $r));
        $this->assertSame('success', $this->notify($payment, $r));
        Environment::$queue->assertPushed(OrderHandleJob::class, 1);
        (new OrderHandleJob($order->trade_no))->handle();
        $this->assertSame(10000, (int)$user->fresh()->balance);
        $this->assertSame('receipt-1', $order->fresh()->callback_no);
    }

    public function testDifferentVerifiedReceiptCreditsWalletOnceWithoutReopening()
    {
        $payment = $this->payment();
        $user = $this->user();
        $order = $this->order($user, ['payment_id' => $payment->id]);
        $this->notify($payment, $this->receipt($payment, $order));
        $receipt = $this->receipt($payment, $order, ['trade_no' => 'different-receipt']);
        $this->assertSame('success', $this->notify($payment, $receipt));
        $this->assertSame('success', $this->notify($payment, $receipt));
        $this->assertSame('receipt-1', $order->fresh()->callback_no);
        $this->assertSame(10000, (int)$user->fresh()->balance);
        Environment::$queue->assertPushed(OrderHandleJob::class, 1);
    }

    public function testReceiptCannotBeReusedForAnotherOrder()
    {
        $payment = $this->payment();
        $user = $this->user();
        $first = $this->order($user, ['payment_id' => $payment->id]);
        $second = $this->order($user, ['payment_id' => $payment->id]);
        $this->notify($payment, $this->receipt($payment, $first));
        try {
            $this->notify($payment, $this->receipt($payment, $second));
            $this->fail('Receipt already claimed by another order');
        } catch (HttpException $e) {
            $this->assertSame(1, (int)$first->fresh()->status);
            $this->assertSame(0, (int)$second->fresh()->status);
        }
    }

    public function testInvalidSignatureAndEmptyKeyDoNotAuthenticate()
    {
        $payment = $this->payment();
        $order = $this->order($this->user(), ['payment_id' => $payment->id]);
        $r = $this->receipt($payment, $order);
        $params = $r->input(); $params['sign'] = str_repeat('0', 32);
        $this->assertFalse((new EPay($payment->config))->notify($params));
        $this->assertFalse((new EPay(['pid' => 'merchant-1', 'key' => '']))->notify($r->input()));
    }

    public function testLegacyMd5WithoutSignTypeAndUppercaseSignatureRemainSupported()
    {
        $payment = $this->payment();
        $order = $this->order($this->user(), ['payment_id' => $payment->id]);
        $params = $this->receipt($payment, $order)->input();
        unset($params['sign_type']); $params['sign'] = strtoupper($params['sign']);
        $r = Request::create('/', 'POST', $params);
        $this->assertSame('success', $this->notify($payment, $r));
    }

    public function testPendingReceiptDoesNotSettle()
    {
        $payment = $this->payment();
        $order = $this->order($this->user(), ['payment_id' => $payment->id]);
        $this->assertSame('fail', $this->notify($payment, $this->receipt($payment, $order, ['trade_status' => 'WAIT_BUYER_PAY'])));
        $this->assertSame(0, (int)$order->fresh()->status);
        Environment::$queue->assertNothingPushed();
    }
}
