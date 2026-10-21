<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        try {
            $verify = PaymentService::notifyRequest($method, $uuid, $request);
            if (!$verify) abort(500, 'verify error');
            // Signed non-payment events are acknowledgements, not settlements.
            if (is_string($verify)) return $verify;
            if (!is_array($verify) || empty($verify['trade_no']) || empty($verify['callback_no'])) abort(500, 'verify error');
            if (!$this->handle($verify)) {
                abort(500, 'handle error');
            }
            return(isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            abort(500, 'fail');
        }
    }

    private function handle(array $verify)
    {
        $order = \App\Services\PaymentAttemptService::resolve($verify);
        if (!$order) {
            abort(500, 'order is not found');
        }
        $orderService = new OrderService($order);
        if (!$orderService->paid($verify['callback_no'], $verify)) {
            return false;
        }
        if (!$orderService->order->wasChanged('status')) return true;
        $telegramService = new TelegramService();
        $message = sprintf(
            "💰成功收款%s元\n———————————————\n订单号：%s",
            $order->total_amount / 100,
            $order->trade_no
        );
        $telegramService->sendMessageWithAdmin($message);
        return true;
    }
}
