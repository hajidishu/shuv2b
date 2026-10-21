<?php

namespace App\Services;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Utils\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    CONST STR_TO_TIME = [
        'month_price' => 1,
        'quarter_price' => 3,
        'half_year_price' => 6,
        'year_price' => 12,
        'two_year_price' => 24,
        'three_year_price' => 36
    ];
    public $order;
    public $user;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function open()
    {
        DB::transaction(function () {
            $order = $this->lockOrder();
            if (!$order) abort(500, '订单或用户不存在');
            // Jobs can be duplicated or contain an order read before another worker committed.
            if ((int)$order->status !== 1) return;
            if (!$this->hasValidAmounts($order)) abort(500, '订单金额有误');

            if ((int)$order->type === 9) {
                $this->user->balance += $order->total_amount + $this->getbounus($order->total_amount);
            } else {
                $plan = Plan::find($order->plan_id);
                if (!$plan) abort(500, '订阅计划不存在');
                $this->consumeSurplusOrders($order);
                $this->user->balance += $order->refund_amount;

                switch ((string)$order->period) {
                    case 'onetime_price':
                        $this->buyByOneTime($order, $plan);
                        break;
                    case 'reset_price':
                        $this->buyByResetTraffic();
                        break;
                    default:
                        $this->buyByPeriod($order, $plan);
                }
                switch ((int)$order->type) {
                    case 1:
                        $this->openEvent(config('v2board.new_order_event_id', 0));
                        break;
                    case 2:
                        $this->openEvent(config('v2board.renew_order_event_id', 0));
                        break;
                    case 3:
                        $this->openEvent(config('v2board.change_order_event_id', 0));
                        break;
                }
                $this->setSpeedLimit($plan->speed_limit);
            }

            if (!$this->user->save()) abort(500, '开通失败');
            $order->status = 3;
            if (!$order->save()) abort(500, '开通失败');
        }, 3);
    }

    // All order money operations lock the owner before the order.
    private function lockOrder(): ?Order
    {
        $this->user = User::where('id', $this->order->user_id)->lockForUpdate()->first();
        $order = Order::where('id', $this->order->id)->lockForUpdate()->first();
        if (!$this->user || !$order || (int)$order->user_id !== (int)$this->user->id) return null;
        $this->order = $order;
        return $order;
    }

    private function consumeSurplusOrders(Order $order): void
    {
        if (!$order->surplus_order_ids) return;
        $ids = array_values(array_unique(array_map('intval', $order->surplus_order_ids)));
        if (in_array((int)$order->id, $ids, true)) abort(500, '折抵订单有误');
        $sources = Order::where('user_id', $order->user_id)
            ->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        if ($sources->count() !== count($ids)) abort(500, '折抵订单不存在');
        $paidValue = 0;
        foreach ($sources as $source) {
            if ((int)$source->status !== 3) abort(500, '折抵订单已被使用');
            if (!in_array($source->period, ['deposit', 'reset_price'], true)) {
                $paidValue += max(0, (int)$source->total_amount + (int)$source->balance_amount +
                    (int)$source->surplus_amount - (int)$source->refund_amount);
            }
        }
        if ((int)$order->surplus_amount > $paidValue || (int)$order->refund_amount > $paidValue) abort(500, '折抵金额超过已付价值');
        foreach ($sources as $source) {
            $source->status = 4;
            if (!$source->save()) abort(500, '折抵失败');
        }
    }


    public function setOrderType(User $user)
    {
        $order = $this->order;
        if ($order->total_amount < 0) abort(500, '订单金额有误');
        if ($order->period === 'deposit'){
            $order->type = 9;
        } else if ($order->period === 'reset_price') {
            $order->type = 4;
        } else if ($user->plan_id !== NULL && $order->plan_id !== $user->plan_id && ($user->expired_at > time() || $user->expired_at === NULL)) {
            if (!(int)config('v2board.plan_change_enable', 1)) abort(500, '目前不允许更改订阅，请联系客服或提交工单操作');
            $order->type = 3;
            if ((int)config('v2board.surplus_enable', 1)) $this->getSurplusValue($user, $order);
            if ($order->surplus_amount >= $order->total_amount) {
                $order->refund_amount = $order->surplus_amount - $order->total_amount;
                $order->total_amount = 0;
            } else {
                $order->total_amount = $order->total_amount - $order->surplus_amount;
            }
        } else if ($user->expired_at > time() && $order->plan_id == $user->plan_id) { // 用户订阅未过期且购买订阅与当前订阅相同 === 续费
            $order->type = 2;
        } else { // 新购
            $order->type = 1;
        }
    }

    public function setVipDiscount(User $user)
    {
        $order = $this->order;
        $price = filter_var($order->total_amount, FILTER_VALIDATE_INT);
        if ($price === false || $price < 0) abort(500, '订单金额有误');
        $couponDiscount = max(0, (int)round($order->discount_amount ?? 0));
        $vipRate = max(0, min(100, (int)$user->discount));
        $vipDiscount = (int)round($price * ($vipRate / 100));
        // Discounts are integer cents and their combined value cannot exceed the price.
        $order->discount_amount = min($price, $couponDiscount + $vipDiscount);
        $order->total_amount = $price - $order->discount_amount;
    }

    public function setInvite(User $user):void
    {
        $order = $this->order;
        if ($user->invite_user_id && ($order->total_amount <= 0)) return;
        $order->invite_user_id = $user->invite_user_id;
        $inviter = User::find($user->invite_user_id);
        if (!$inviter) return;
        $isCommission = false;
        switch ((int)$inviter->commission_type) {
            case 0:
                $commissionFirstTime = (int)config('v2board.commission_first_time_enable', 1);
                $isCommission = (!$commissionFirstTime || ($commissionFirstTime && !$this->haveValidOrder($user)));
                break;
            case 1:
                $isCommission = true;
                break;
            case 2:
                $isCommission = !$this->haveValidOrder($user);
                break;
        }

        if (!$isCommission) return;
        if ($inviter && $inviter->commission_rate) {
            $order->commission_balance = $order->total_amount * ($inviter->commission_rate / 100);
        } else {
            $order->commission_balance = $order->total_amount * (config('v2board.invite_commission', 10) / 100);
        }
    }

    private function haveValidOrder(User $user)
    {
        return Order::where('user_id', $user->id)
            ->whereNotIn('status', [0, 2])
            ->first();
    }

    private function getSurplusValue(User $user, Order $order)
    {
        if ($user->expired_at === NULL) {
            $this->getSurplusValueByOneTime($user, $order);
        } else {
            $this->getSurplusValueByPeriod($user, $order);
        }
    }


    private function getSurplusValueByOneTime(User $user, Order $order)
    {
        $lastOneTimeOrder = Order::where('user_id', $user->id)
            ->where('period', 'onetime_price')
            ->where('status', 3)
            ->orderBy('id', 'DESC')
            ->first();
        if (!$lastOneTimeOrder) return;
        $nowUserTraffic = $user->transfer_enable / 1073741824;
        if ($nowUserTraffic == 0) return;
        $paidTotalAmount = max(0, $lastOneTimeOrder->total_amount + $lastOneTimeOrder->balance_amount);
        if ($paidTotalAmount == 0) return;
        $notUsedTraffic = $nowUserTraffic - (($user->u + $user->d) / 1073741824);
        $remainingTrafficRatio = $notUsedTraffic / $nowUserTraffic;
        $result = $remainingTrafficRatio * $paidTotalAmount;
        $order->surplus_amount = (int)floor(min($paidTotalAmount, max($result, 0)));
        $orderModel = Order::where('user_id', $user->id)->whereNotIn('period', ['reset_price', 'deposit'])->where('status', 3);
        $order->surplus_order_ids = array_column($orderModel->get()->toArray(), 'id');
    }

    private function getSurplusValueByPeriod(User $user, Order $order)
    {
        $orders = Order::where('user_id', $user->id)
            ->where('period', '!=', 'reset_price')
            ->where('period', '!=', 'onetime_price')
            ->where('period', '!=', 'deposit')
            ->where('status', 3)
            ->get()
            ->toArray();
        if (!$orders) return;
        $orderAmountSum = 0;
        $orderMonthSum = 0;
        $lastValidateAt = null;
        foreach ($orders as $item) {
            $period = self::STR_TO_TIME[$item['period']];
            $orderEndTime = strtotime("+{$period} month", $item['created_at']);
            if ($orderEndTime < time()) continue;
            $lastValidateAt = $item['created_at'] > $lastValidateAt ? $item['created_at'] : $lastValidateAt;
            $orderMonthSum += $period;
            $orderAmountSum += max(0, $item['total_amount'] + $item['balance_amount'] + $item['surplus_amount'] - $item['refund_amount']);
        }
        if ($lastValidateAt === null) return;
    
        $expiredAtByOrder = strtotime("+{$orderMonthSum} month", $lastValidateAt);
        $expiredAtByUser = $user->expired_at;
        if ($expiredAtByOrder < time() || $expiredAtByUser < time()) return;
        $orderSurplusSecond = $expiredAtByUser - time();
        $orderRangeSecond = $expiredAtByOrder - $lastValidateAt;
    
        $totalTraffic = $user->transfer_enable;
        $usedTraffic = ($user->u + $user->d);
        if ($totalTraffic == 0) return;
    
        $remainingTrafficRatio = ($totalTraffic - $usedTraffic) / $totalTraffic;
    
        $avgPricePerSecond = $orderAmountSum / $orderRangeSecond;
        if ($orderRangeSecond <= 31 * 86400) {
            $remainingExpiredTimeRatio = $orderSurplusSecond / $orderRangeSecond;
            $surplusRatio = min($remainingExpiredTimeRatio, $remainingTrafficRatio);
            $orderSurplusAmount = $avgPricePerSecond * $orderSurplusSecond * $surplusRatio;
        } else {
            $monthSeconds = 30 * 86400;
            $firstMonthRemainSeconds = $orderSurplusSecond % $monthSeconds;
            $surplusRatio = min($firstMonthRemainSeconds / $monthSeconds, $remainingTrafficRatio);
            $laterMonthsSeconds = $orderSurplusSecond - $firstMonthRemainSeconds;
            $orderSurplusAmount = $avgPricePerSecond * $monthSeconds * $surplusRatio +
                                  $avgPricePerSecond * $laterMonthsSeconds;
        }
    
        $order->surplus_amount = (int)floor(min($orderAmountSum, max($orderSurplusAmount, 0)));
        $order->surplus_order_ids = array_column($orders, 'id');
    }

    public function paid(string $callbackNo, array $payment = [])
    {
        if ($payment) {
            $settlement = new PaymentSettlementService();
            $result = $settlement->settle($this->order, $callbackNo, $payment);
            if ($settlement->order) $this->order = $settlement->order;
            return $result;
        }
        return DB::transaction(function () use ($callbackNo) {
            $order = $this->lockOrder();
            if (!$order) return false;
            if (!$this->hasValidAmounts($order)) return false;
            if (in_array((int)$order->status, [1, 3, 4], true)) return true;
            if ((int)$order->status !== 0) return false;
            $order->status = 1;
            $order->paid_at = time();
            $order->callback_no = $callbackNo;
            if (!$order->save()) throw new \RuntimeException('支付状态保存失败');
            DB::afterCommit(function () use ($order) {
                try {
                    OrderHandleJob::dispatch($order->trade_no);
                } catch (\Throwable $e) {
                    // check:order will retry committed paid orders if the queue is unavailable.
                    Log::error('订单任务派发失败', ['trade_no' => $order->trade_no, 'exception' => $e]);
                }
            });
            return true;
        }, 3);
    }

    public static function hasValidAmounts(Order $order): bool
    {
        foreach (['total_amount', 'handling_amount', 'balance_amount', 'discount_amount', 'surplus_amount', 'refund_amount'] as $field) {
            if ($order->$field !== null && $order->$field < 0) return false;
        }
        return (int)$order->refund_amount <= (int)$order->surplus_amount;
    }

    public function cancel():bool
    {
        return DB::transaction(function () {
            $order = $this->lockOrder();
            if (!$order || (int)$order->status !== 0) return false;
            if (!(new CouponService())->release($order)) throw new \RuntimeException('优惠券配额释放失败');
            $this->user->balance += $order->balance_amount;
            if (!$this->user->save()) throw new \RuntimeException('退款保存失败');
            $order->status = 2;
            if (!$order->save()) throw new \RuntimeException('取消状态保存失败');
            return true;
        }, 3);
    }

    private function setSpeedLimit($speedLimit)
    {
        $this->user->speed_limit = $speedLimit;
    }

    private function buyByResetTraffic()
    {
        $this->user->u = 0;
        $this->user->d = 0;
    }

    private function buyByPeriod(Order $order, Plan $plan)
    {
        // change plan process
        if ((int)$order->type === 3) {
            $this->user->expired_at = time();
        }
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
        $this->user->device_limit = $plan->device_limit;
        // 从一次性转换到循环
        if ($this->user->expired_at === NULL) $this->buyByResetTraffic();
        // 新购
        if ($order->type === 1) $this->buyByResetTraffic();

        // 到期当天续费刷新流量
        $expireDay = date('d', $this->user->expired_at);
        $expireMonth = date('m', $this->user->expired_at);
        $today = date('d');
        $currentMonth = date('m');
        if ($order->type === 2 && $expireMonth == $currentMonth && $expireDay === $today ) {
            $this->buyByResetTraffic();
        }

        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = $this->getTime($order->period, $this->user->expired_at);
    }

    private function buyByOneTime(Order $order, Plan $plan)
    {
        $transfer_enable = $plan->transfer_enable;
        if (!$order->surplus_order_ids) {
            $notUsedTraffic = ($this->user->transfer_enable - ($this->user->u + $this->user->d)) / 1073741824;
            if ($notUsedTraffic > 0 && $this->user->expired_at == NULL) {
                $transfer_enable += $notUsedTraffic;
            }
        }
        $this->buyByResetTraffic();
        $this->user->transfer_enable = $transfer_enable * 1073741824;
        $this->user->device_limit = $plan->device_limit;
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = NULL;
    }

    private function getTime($str, $timestamp)
    {
        if ($timestamp < time()) {
            $timestamp = time();
        }
        switch ($str) {
            case 'month_price':
                return strtotime('+1 month', $timestamp);
            case 'quarter_price':
                return strtotime('+3 month', $timestamp);
            case 'half_year_price':
                return strtotime('+6 month', $timestamp);
            case 'year_price':
                return strtotime('+12 month', $timestamp);
            case 'two_year_price':
                return strtotime('+24 month', $timestamp);
            case 'three_year_price':
                return strtotime('+36 month', $timestamp);
        }
    }

    private function openEvent($eventId)
    {
        switch ((int) $eventId) {
            case 0:
                break;
            case 1:
                $this->buyByResetTraffic();
                break;
        }
    }

    private function getbounus($total_amount) {
        $deposit_bounus = config('v2board.deposit_bounus', []);
        if (empty($deposit_bounus) || $deposit_bounus[0] === null) {
            return 0;
        }
        $add = 0;
        foreach ($deposit_bounus as $tier) {
            list($amount, $bounus) = explode(':', $tier);
            $amount = (float)$amount * 100;
            $bounus = (float)$bounus * 100;
            $amount = (int)$amount;
            $bounus = (int)$bounus;
            if ($total_amount >= $amount) {
                $add = max($add, $bounus);
            }
        }
        return $add;
    }
}
