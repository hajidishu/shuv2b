<?php

namespace Tests\P1;

use App\Http\Controllers\V1\Guest\PaymentController;
use App\Jobs\OrderHandleJob;
use App\Models\Payment;
use App\Payments\BTCPay;
use App\Payments\Coinbase;
use App\Services\PaymentService;
use App\Utils\Money;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentTest extends DatabaseTestCase
{
    private function payment($method, array $config)
    {
        return Payment::create(['uuid' => uniqid('gateway-'), 'payment' => $method, 'config' => $config]);
    }

    private function signedRequest($body, $header, $signature, $params = [])
    {
        $request = Request::create('/', 'POST', $params, [], [], [], $body);
        $request->headers->set($header, $signature);
        Environment::$app->instance('request', $request);
        return $request;
    }

    public function nonPaymentCoinbaseEvents()
    {
        return [['charge:created'], ['charge:pending'], ['charge:failed'], ['charge:delayed']];
    }

    /** @dataProvider nonPaymentCoinbaseEvents */
    public function testSignedUnpaidCoinbaseEventsDoNotSettle($type)
    {
        $payment = $this->payment('Coinbase', ['coinbase_webhook_key' => 'test-secret']);
        $order = $this->order($this->user(), ['payment_id' => $payment->id]);
        $body = json_encode(['event' => ['type' => $type, 'id' => 'event-1', 'data' => ['id' => 'charge-1', 'metadata' => ['outTradeNo' => $order->trade_no]]]]);
        $r = $this->signedRequest($body, 'x-cc-webhook-signature', hash_hmac('sha256', $body, 'test-secret'));
        $this->assertSame('success', (new PaymentController())->notify('Coinbase', $payment->uuid, $r));
        $this->assertSame(0, (int)$order->fresh()->status);
        Environment::$queue->assertNothingPushed();
    }

    public function testConfirmedCoinbaseChecksAmountAndSettlesOnlyOnce()
    {
        $user = $this->user();
        $payment = $this->payment('Coinbase', ['coinbase_webhook_key' => 'test-secret']);
        $order = $this->order($user, ['payment_id' => $payment->id]);
        $body = json_encode(['event' => ['type' => 'charge:confirmed', 'id' => 'event-1', 'data' => ['id' => 'charge-1', 'metadata' => ['outTradeNo' => $order->trade_no], 'pricing' => ['local' => ['amount' => '100.00', 'currency' => 'CNY']]]]], JSON_PRETTY_PRINT) . "\n";
        $r = $this->signedRequest($body, 'X-CC-WEBHOOK-SIGNATURE', hash_hmac('sha256', $body, 'test-secret'));
        $controller = new PaymentController();
        $this->assertSame('success', $controller->notify('Coinbase', $payment->uuid, $r));
        $this->assertSame('success', $controller->notify('Coinbase', $payment->uuid, $r));
        Environment::$queue->assertPushed(OrderHandleJob::class, 1);
        (new OrderHandleJob($order->trade_no))->handle();
        $this->assertSame(10000, (int)$user->fresh()->balance);
        $this->assertSame('charge-1', $order->fresh()->callback_no);
    }

    public function testInvalidCoinbaseSignatureIsRejected()
    {
        $r = $this->signedRequest('{"event":{"type":"charge:confirmed"}}', 'X-Cc-Webhook-Signature', 'invalid');
        $this->expectException(HttpException::class);
        (new Coinbase(['coinbase_webhook_key' => 'secret']))->notify([]);
    }

    public function emptyWebhookSecrets()
    {
        return [['Coinbase', 'coinbase_webhook_key'], ['BTCPay', 'btcpay_webhook_key'], ['CoinPayments', 'coinpayments_ipn_secret']];
    }

    /** @dataProvider emptyWebhookSecrets */
    public function testEmptyWebhookSecretCannotAuthenticateReceipt($method, $key)
    {
        $this->signedRequest('{}', 'HMAC', hash_hmac('sha512', '{}', ''));
        $class = '\\App\\Payments\\' . $method;
        $this->expectException(HttpException::class);
        (new $class([$key => '']))->notify([]);
    }

    public function testBtcpayCreatedEventDoesNotFetchOrSettleInvoice()
    {
        $body = json_encode(['type' => 'InvoiceCreated', 'invoiceId' => 'invoice-1']);
        $this->signedRequest($body, 'BTCPAY-SIG', 'sha256=' . hash_hmac('sha256', $body, 'secret'));
        $this->assertSame('success', (new BTCPay(['btcpay_webhook_key' => 'secret']))->notify([]));
        $this->assertSame([], PaymentHttpFixture::$requests);
    }

    public function testBtcpaySettledEventAlsoRequiresSettledInvoice()
    {
        $config = ['btcpay_url' => 'https://fixture.test/', 'btcpay_storeId' => 'store-1', 'btcpay_api_key' => 'api-secret', 'btcpay_webhook_key' => 'secret'];
        $payment = $this->payment('BTCPay', $config);
        $order = $this->order($this->user(), ['payment_id' => $payment->id]);
        $body = json_encode(['type' => 'InvoiceSettled', 'invoiceId' => 'invoice-1']);
        $r = $this->signedRequest($body, 'btcpay-sig', 'sha256=' . hash_hmac('sha256', $body, 'secret'));
        $url = 'https://fixture.test/api/v1/stores/store-1/invoices/invoice-1';
        PaymentHttpFixture::$responses[$url] = json_encode(['status' => 'Processing', 'amount' => '100', 'currency' => 'CNY', 'metadata' => ['orderId' => $order->trade_no]]);
        $this->assertFalse((new BTCPay($config))->notify([]));
        $this->assertSame(0, (int)$order->fresh()->status);
        PaymentHttpFixture::$responses[$url] = json_encode(['status' => 'Settled', 'amount' => '100', 'currency' => 'CNY', 'metadata' => ['orderId' => $order->trade_no]]);
        $this->assertSame('success', (new PaymentController())->notify('BTCPay', $payment->uuid, $r));
        $this->assertSame(1, (int)$order->fresh()->status);
    }

    public function invalidCoinpaymentsAmounts()
    {
        return [['0.01', 'CNY'], ['100', 'USD'], ['100.001', 'CNY'], ['-100', 'CNY']];
    }

    /** @dataProvider invalidCoinpaymentsAmounts */
    public function testValidlySignedCoinpaymentsUnderpaymentOrWrongCurrencyIsRejected($amount, $currency)
    {
        $payment = $this->payment('CoinPayments', ['coinpayments_merchant_id' => 'merchant-1', 'coinpayments_ipn_secret' => 'secret', 'coinpayments_currency' => 'CNY']);
        $user = $this->user();
        $order = $this->order($user, ['payment_id' => $payment->id]);
        $params = ['ipn_type' => 'simple', 'ipn_mode' => 'hmac', 'merchant' => 'merchant-1', 'txn_id' => 'txn-1', 'status' => 100, 'item_number' => $order->trade_no, 'amount1' => $amount, 'currency1' => $currency];
        $body = http_build_query($params);
        $r = $this->signedRequest($body, 'HMAC', hash_hmac('sha512', $body, 'secret'), $params);
        try {
            (new PaymentController())->notify('CoinPayments', $payment->uuid, $r);
            $this->fail('Mismatched receipt must be rejected');
        } catch (HttpException $e) {
            $this->assertSame(0, (int)$order->fresh()->status);
            $this->assertSame(0, (int)$user->fresh()->balance);
            Environment::$queue->assertNothingPushed();
        }
    }

    public function testCoinpaymentsUsesRawBodyAndIncludesHandlingFeeInAmountCheck()
    {
        $payment = $this->payment('CoinPayments', ['coinpayments_merchant_id' => 'merchant-1', 'coinpayments_ipn_secret' => 'secret', 'coinpayments_currency' => 'CNY']);
        $user = $this->user();
        $order = $this->order($user, ['payment_id' => $payment->id, 'handling_amount' => 250]);
        // Intentionally not sorted: a correct signature covers exactly the raw body.
        $params = ['ipn_type' => 'simple', 'ipn_mode' => 'hmac', 'merchant' => 'merchant-1', 'txn_id' => 'txn-1', 'status' => 100, 'item_number' => $order->trade_no, 'amount1' => '102.50', 'currency1' => 'CNY'];
        $body = http_build_query($params);
        $r = $this->signedRequest($body, 'hmac', hash_hmac('sha512', $body, 'secret'), $params);
        $controller = new PaymentController();
        $this->assertSame('IPN OK', $controller->notify('CoinPayments', $payment->uuid, $r));
        $this->assertSame('IPN OK', $controller->notify('CoinPayments', $payment->uuid, $r));
        (new OrderHandleJob($order->trade_no))->handle();
        $this->assertSame(10000, (int)$user->fresh()->balance);
        Environment::$queue->assertPushed(OrderHandleJob::class, 1);
    }

    public function testReceiptCannotSettleOrderAssignedToAnotherGateway()
    {
        $config = ['coinpayments_merchant_id' => 'merchant-1', 'coinpayments_ipn_secret' => 'secret', 'coinpayments_currency' => 'CNY'];
        $payment = $this->payment('CoinPayments', $config);
        $other = $this->payment('CoinPayments', $config);
        $order = $this->order($this->user(), ['payment_id' => $other->id]);
        $params = ['ipn_type' => 'simple', 'ipn_mode' => 'hmac', 'merchant' => 'merchant-1', 'status' => 100, 'txn_id' => 'txn-1', 'item_number' => $order->trade_no, 'amount1' => '100', 'currency1' => 'CNY'];
        $body = http_build_query($params);
        $r = $this->signedRequest($body, 'HMAC', hash_hmac('sha512', $body, 'secret'), $params);
        try {
            (new PaymentController())->notify('CoinPayments', $payment->uuid, $r);
            $this->fail('Wrong channel must be rejected');
        } catch (HttpException $e) {
            $this->assertSame(0, (int)$order->fresh()->status);
        }
    }

    public function testUnsignedQueryFieldsCannotSupplyMissingPaymentMetadata()
    {
        $payment = $this->payment('CoinPayments', ['coinpayments_merchant_id' => 'merchant-1', 'coinpayments_ipn_secret' => 'secret', 'coinpayments_currency' => 'CNY']);
        $order = $this->order($this->user(), ['payment_id' => $payment->id]);
        $bodyParams = ['ipn_type' => 'simple', 'ipn_mode' => 'hmac', 'merchant' => 'merchant-1', 'status' => 100, 'txn_id' => 'txn-1', 'amount1' => '100', 'currency1' => 'CNY'];
        $body = http_build_query($bodyParams);
        $merged = array_merge($bodyParams, ['item_number' => $order->trade_no]);
        $r = $this->signedRequest($body, 'HMAC', hash_hmac('sha512', $body, 'secret'), $merged);
        try {
            (new PaymentController())->notify('CoinPayments', $payment->uuid, $r);
            $this->fail('Unsigned metadata must not be trusted');
        } catch (HttpException $e) {
            $this->assertSame(0, (int)$order->fresh()->status);
        }
    }

    public function testSignedWithdrawalNotificationCannotBeUsedAsPayment()
    {
        $payment = $this->payment('CoinPayments', ['coinpayments_merchant_id' => 'merchant-1', 'coinpayments_ipn_secret' => 'secret', 'coinpayments_currency' => 'CNY']);
        $order = $this->order($this->user(), ['payment_id' => $payment->id]);
        $params = ['ipn_type' => 'withdrawal', 'ipn_mode' => 'hmac', 'merchant' => 'merchant-1', 'status' => 2, 'txn_id' => 'txn-1', 'item_number' => $order->trade_no, 'amount1' => '100', 'currency1' => 'CNY'];
        $body = http_build_query($params);
        $r = $this->signedRequest($body, 'HMAC', hash_hmac('sha512', $body, 'secret'), $params);
        try {
            (new PaymentController())->notify('CoinPayments', $payment->uuid, $r);
            $this->fail('Withdrawal cannot be a purchase receipt');
        } catch (HttpException $e) {
            $this->assertSame(0, (int)$order->fresh()->status);
        }
    }

    public function testCallbackPathCannotSelectAnotherAdapterForGateway()
    {
        $payment = $this->payment('CoinPayments', ['coinpayments_ipn_secret' => 'secret']);
        $this->expectException(HttpException::class);
        new PaymentService('Coinbase', null, $payment->uuid);
    }

    public function amountCases()
    {
        return [['100.00', 10000], ['0.010000', 1], ['00100', 10000], ['0', 0], ['1.001', null], ['-1', null], ['1e2', null], [true, null], [[], null], ['9223372036854775808', null]];
    }

    /** @dataProvider amountCases */
    public function testMoneyParsingDoesNotRoundUpUnderpayment($value, $expected)
    {
        $this->assertSame($expected, Money::toCents($value));
    }
}
