<?php
namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PaymentAttemptService
{
    const TABLE = 'v2_payment_attempt';

    public function begin($userId, $tradeNo, $paymentId, $clientToken = null, $returnContext = null)
    {
        PaymentLedgerSchema::requireInstalled();
        return DB::transaction(function () use ($userId, $tradeNo, $paymentId, $clientToken, $returnContext) {
            $gateway = Payment::where('id', $paymentId)->lockForUpdate()->first();
            if (!$gateway || (int)$gateway->enable !== 1) abort(422, __('Payment method is not available'));
            if (!User::where('id', $userId)->lockForUpdate()->first()) abort(404, '用户不存在');
            $order = Order::where('user_id', $userId)->where('trade_no', $tradeNo)->lockForUpdate()->first();
            if (!$order || (int)$order->status !== 0 || (int)$order->total_amount <= 0) abort(409, __('Order does not exist or has been paid'));
            $fee = (int)round($order->total_amount * ($gateway->handling_fee_percent / 100) + $gateway->handling_fee_fixed);
            if ($fee < 0 || !OrderService::hasValidAmounts($order)) abort(422, '订单或手续费金额有误');
            $configHash = hash('sha256', json_encode([$gateway->payment, $gateway->config, $gateway->notify_domain, $fee]));
            // Changing frontend or route must not reuse a checkout bound to an old URL.
            $requestHash = hash('sha256', json_encode([(string)$clientToken, $returnContext], JSON_THROW_ON_ERROR));
            $attempt = DB::table(self::TABLE)->where('order_id', $order->id)->where('payment_id', $paymentId)
                ->where('config_hash', $configHash)->where('request_hash', $requestHash)->where('base_amount', $order->total_amount)
                ->orderByDesc('id')->lockForUpdate()->first();
            $order->payment_id = $paymentId; $order->handling_amount = $fee ?: null; $order->save();
            // Coalesce duplicate clicks briefly; do not keep returning an expired QR/session
            // forever. Old references remain in the ledger and can still receive payment.
            if ($attempt && $attempt->response && $attempt->status === 'pending') {
                if ($attempt->updated_at > time() - 60) return $attempt;
                $attempt = null;
            }
            if ($attempt && $attempt->status === 'paid') abort(409, '此付款已收到，请刷新订单');
            if ($attempt && $attempt->status === 'creating' && $attempt->lease_at > time() - 120) abort(409, '支付链接正在生成，请稍后重试');
            $lease = bin2hex(random_bytes(16));
            if ($attempt) {
                DB::table(self::TABLE)->where('id', $attempt->id)->update(['status' => 'creating', 'lease_token' => $lease, 'lease_at' => time(), 'updated_at' => time()]);
                return DB::table(self::TABLE)->where('id', $attempt->id)->first();
            }
            $id = DB::table(self::TABLE)->insertGetId(['trade_no' => 'p' . bin2hex(random_bytes(15)), 'order_id' => $order->id,
                'user_id' => $userId, 'payment_id' => $paymentId, 'method' => $gateway->payment, 'gateway_uuid' => $gateway->uuid,
                'config_snapshot' => PaymentConfigSnapshot::seal($gateway->toArray()), 'config_hash' => $configHash,
                'request_hash' => $requestHash, 'base_amount' => $order->total_amount, 'handling_amount' => $fee,
                'total_amount' => (int)$order->total_amount + $fee, 'status' => 'creating', 'lease_token' => $lease,
                'lease_at' => time(), 'created_at' => time(), 'updated_at' => time()]);
            return DB::table(self::TABLE)->where('id', $id)->first();
        }, 3);
    }

    public function quote($attempt, PaymentService $service)
    {
        if (strpos($attempt->method, 'Stripe') !== 0 || $attempt->gateway_amount_minor !== null) return $attempt;
        $quote = $service->quote($attempt->total_amount);
        if (!is_array($quote) || !is_int($quote['amount_minor'] ?? null) || $quote['amount_minor'] <= 0 ||
            !is_string($quote['currency'] ?? null) || !preg_match('/\A[a-z]{3}\z/', $quote['currency'])) abort(422, '支付报价无效');
        DB::table(self::TABLE)->where('id', $attempt->id)->whereNull('gateway_amount_minor')->update([
            'gateway_amount_minor' => $quote['amount_minor'], 'gateway_currency' => $quote['currency'], 'updated_at' => time()]);
        return DB::table(self::TABLE)->where('id', $attempt->id)->first();
    }

    public function complete($attempt, array $response)
    {
        if (!isset($response['type']) || !array_key_exists('data', $response)) throw new \RuntimeException('支付网关未返回有效链接');
        DB::transaction(function () use ($attempt, $response) {
            $row = DB::table(self::TABLE)->where('id', $attempt->id)->lockForUpdate()->first();
            if (!$row || $row->lease_token !== $attempt->lease_token) return;
            DB::table(self::TABLE)->where('id', $row->id)->update(['response' => json_encode($response, JSON_THROW_ON_ERROR),
                'status' => $row->status === 'paid' ? 'paid' : 'pending', 'lease_token' => null, 'lease_at' => null, 'updated_at' => time()]);
        }, 3);
    }

    public function retry($attempt)
    {
        DB::table(self::TABLE)->where('id', $attempt->id)->where('lease_token', $attempt->lease_token)->where('status', 'creating')
            ->update(['status' => 'retry', 'lease_token' => null, 'lease_at' => null, 'updated_at' => time()]);
    }

    public static function resolve(array &$payment)
    {
        if (!PaymentLedgerSchema::installed()) return Order::where('trade_no', $payment['trade_no'])->first();
        $attempt = DB::table(self::TABLE)->where('trade_no', $payment['trade_no'])->first();
        if (!$attempt) return Order::where('trade_no', $payment['trade_no'])->first();
        if ((int)$attempt->payment_id !== (int)$payment['payment_id']) return null;
        if (!empty($payment['verified_attempt_id']) && (int)$payment['verified_attempt_id'] !== (int)$attempt->id) return null;
        $payment['attempt_id'] = $attempt->id;
        return Order::find($attempt->order_id);
    }

    public static function adjustments($orderId)
    {
        if (!PaymentLedgerSchema::installed()) return [];
        return DB::table('v2_payment_receipt')->where('order_id', $orderId)->where('disposition', 'wallet')
            ->orderBy('id')->get(['amount','wallet_amount','reason','created_at'])->all();
    }
}
