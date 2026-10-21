<?php
namespace App\Services;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Utils\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentSettlementService
{
    public $order;

    public function settle(Order $source, $callbackNo, array $payment)
    {
        PaymentLedgerSchema::requireInstalled();
        if (!is_string($callbackNo) || $callbackNo === '' || strlen($callbackNo) > 191) return false;
        return DB::transaction(function () use ($source, $callbackNo, $payment) {
            // The channel lock serializes claims for one gateway receipt across all owners.
            $gateway = Payment::where('id', $payment['payment_id'] ?? 0)->lockForUpdate()->first();
            if (!$gateway) return false;
            $user = User::where('id', $source->user_id)->lockForUpdate()->first();
            $order = Order::where('id', $source->id)->lockForUpdate()->first();
            $this->order = $order;
            if (!$user || !$order || (int)$order->user_id !== (int)$user->id || !OrderService::hasValidAmounts($order)) return false;
            $attempt = null;
            if (!empty($payment['attempt_id'])) {
                $attempt = DB::table(PaymentAttemptService::TABLE)->where('id', $payment['attempt_id'])->lockForUpdate()->first();
                if (!$attempt || (int)$attempt->order_id !== (int)$order->id || (int)$attempt->user_id !== (int)$user->id ||
                    (int)$attempt->payment_id !== (int)$gateway->id ||
                    ($attempt->method !== $gateway->payment && (int)($payment['verified_attempt_id'] ?? 0) !== (int)$attempt->id)) return false;
            } elseif (!$gateway->enable || (int)$order->payment_id !== (int)$gateway->id) return false;
            $gross = $attempt ? (int)$attempt->total_amount : (int)$order->total_amount + (int)$order->handling_amount;
            if ($gross <= 0 || !$this->amountMatches($payment, $attempt, $gateway, $gross)) return false;
            $receipt = DB::table('v2_payment_receipt')->where('payment_id', $gateway->id)->where('callback_no', $callbackNo)->lockForUpdate()->first();
            if ($receipt) return (int)$receipt->order_id === (int)$order->id &&
                (!$attempt || (int)$receipt->attempt_id === (int)$attempt->id);
            $claimed = Order::where('payment_id', $gateway->id)->where('callback_no', $callbackNo)->where('id', '!=', $order->id)->lockForUpdate()->first();
            if ($claimed) return false;
            $manual = in_array((int)$order->status, [1,3,4], true) && $order->callback_no === 'manual_operation';
            $alreadyApplied = $manual || (in_array((int)$order->status, [1,3,4], true) &&
                (int)$order->payment_id === (int)$gateway->id && (string)$order->callback_no === $callbackNo);
            $apply = (int)$order->status === 0 && (!$attempt || (int)$attempt->base_amount === (int)$order->total_amount);
            $wallet = !$apply && !$alreadyApplied;
            if (!in_array((int)$order->status, [0,1,2,3,4], true)) return false;
            if ($wallet) {
                // Refund the entire CNY checkout quote, including handling fee, without reopening
                // a canceled purchase or reusing its already-returned balance/coupon reservation.
                $user->balance += $gross;
                if (!$user->save()) throw new \RuntimeException('收款转入余额失败');
            } elseif ($manual) {
                $order->callback_no = $callbackNo; $order->payment_id = $gateway->id;
                if ($attempt) $order->handling_amount = $attempt->handling_amount ?: null;
                if (!$order->save()) throw new \RuntimeException('手工确认收款关联失败');
            } elseif ($apply) {
                $order->payment_id = $gateway->id;
                if ($attempt) $order->handling_amount = $attempt->handling_amount ?: null;
                $order->status = 1; $order->paid_at = time(); $order->callback_no = $callbackNo;
                if (!$order->save()) throw new \RuntimeException('支付状态保存失败');
                DB::afterCommit(function () use ($order) {
                    try { OrderHandleJob::dispatch($order->trade_no); }
                    catch (\Throwable $error) { Log::error('订单任务派发失败', ['trade_no' => $order->trade_no, 'exception' => $error]); }
                });
            }
            $reason = $wallet ? ((int)$order->status === 2 ? 'canceled_order' : ((int)$order->status === 0 ? 'order_changed' : 'additional_payment')) : null;
            DB::table('v2_payment_receipt')->insert(['attempt_id' => $attempt ? $attempt->id : null, 'order_id' => $order->id,
                'user_id' => $user->id, 'payment_id' => $gateway->id, 'callback_no' => $callbackNo, 'amount' => $gross,
                'wallet_amount' => $wallet ? $gross : 0, 'disposition' => $wallet ? 'wallet' : 'applied', 'reason' => $reason, 'created_at' => time()]);
            if ($attempt) DB::table(PaymentAttemptService::TABLE)->where('id', $attempt->id)->update(['status' => 'paid', 'updated_at' => time()]);
            return true;
        }, 3);
    }

    private function amountMatches(array $payment, $attempt, Payment $gateway, $gross)
    {
        if (array_key_exists('amount_minor', $payment)) {
            $amount = filter_var($payment['amount_minor'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $currency = $payment['currency'] ?? null;
            if ($amount === false || !is_string($currency) || !preg_match('/\A[a-zA-Z]{3}\z/', $currency)) return false;
            if ($attempt) return $attempt->gateway_amount_minor !== null && $amount === (int)$attempt->gateway_amount_minor &&
                strtolower($currency) === strtolower((string)$attempt->gateway_currency);
            // Pre-upgrade foreign Stripe orders lack a frozen FX quote. Preserve their signed
            // channel/order binding; all new checkouts have an exact minor-unit snapshot above.
            $configured = $gateway->config['currency'] ?? '';
            if (!is_string($configured) || strtolower($currency) !== strtolower($configured)) return false;
            return strtolower($currency) !== 'cny' || $amount === $gross;
        }
        if (!array_key_exists('amount', $payment)) return true; // Legacy signature-only adapters.
        $amount = Money::toCents($payment['amount']);
        return $amount !== null && $amount > 0 && $amount === $gross &&
            is_string($payment['currency'] ?? null) && is_string($payment['expected_currency'] ?? null) &&
            $payment['currency'] !== '' && $payment['expected_currency'] !== '' &&
            strtoupper($payment['currency']) === strtoupper($payment['expected_currency']);
    }
}
