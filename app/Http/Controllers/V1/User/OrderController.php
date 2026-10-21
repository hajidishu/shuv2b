<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\OrderSave;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\CouponService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PlanService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function fetch(Request $request)
    {
        $model = Order::where('user_id', $request->user['id'])
            ->orderBy('created_at', 'DESC');
        if ($request->input('status') !== null) {
            $model->where('status', $request->input('status'));
        }
        $order = $model->get();
        $plan = Plan::get();
        for ($i = 0; $i < count($order); $i++) {
            for ($x = 0; $x < count($plan); $x++) {
                if ($order[$i]['plan_id'] === $plan[$x]['id']) {
                    $order[$i]['plan'] = $plan[$x];
                }
            }
        }
        return response([
            'data' => $order->makeHidden(['id', 'user_id'])
        ]);
    }

    public function detail(Request $request)
    {
        $order = Order::where('user_id', $request->user['id'])
            ->where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            abort(500, __('Order does not exist or has been paid'));
        }
        $order['payment_adjustments'] = \App\Services\PaymentAttemptService::adjustments($order->id);
        if ($order->plan_id == 0) {
            $order['plan'] = [
                'id' => 0,
                'name' => 'deposit'
            ];
            $order->bounus = $this->getbounus($order->total_amount);
            $order->get_amount = $order->total_amount + $order->bounus;

            return response([
                'data' => $order
            ]);
        }
        $order['plan'] = Plan::find($order->plan_id);
        $order['try_out_plan_id'] = (int)config('v2board.try_out_plan_id');
        if (!$order['plan']) {
            abort(500, __('Subscription plan does not exist'));
        }
        if ($order->surplus_order_ids) {
            $order['surplus_orders'] = Order::whereIn('id', $order->surplus_order_ids)->get();
        }
        return response([
            'data' => $order
        ]);
    }

    public function save(OrderSave $request)
    {
        $tradeNo = DB::transaction(function () use ($request) {
            $user = User::where('id', $request->user['id'])->lockForUpdate()->first();
            if (!$user) abort(500, __('The user does not exist'));
            $userService = new UserService();
            if ($userService->isNotCompleteOrderByUserId($request->user['id'])) {
                abort(500, __('You have an unpaid or pending order, please try again later or cancel it'));
            }
            if ($request->input('plan_id') == 0) {
                $amount = $request->input('deposit_amount');
                if ($amount <= 0) {
                    abort(500, __('Failed to create order, deposit amount must be greater than 0'));
                }
                if ($amount >= 9999999 ) {
                    abort(500, __('Deposit amount too large, please contact the administrator'));
                }
                $order = new Order();
                $orderService = new OrderService($order);
                $order->user_id = $request->user['id'];
                $order->plan_id = $request->input('plan_id');
                $order->period = 'deposit';
                $order->trade_no = Helper::generateOrderNo();
                $order->total_amount = $amount;

                $orderService->setOrderType($user);
                $orderService->setInvite($user);

                if (!$order->save()) {
                    abort(500, __('Failed to create order'));
                }

                return $order->trade_no;
            }
            $planService = new PlanService($request->input('plan_id'));

            $plan = $planService->plan;

            if (!$plan) {
                abort(500, __('Subscription plan does not exist'));
            }

            if ($user->plan_id !== $plan->id && !$planService->haveCapacity() && $request->input('period') !== 'reset_price') {
                abort(500, __('Current product is sold out'));
            }

            if ($plan[$request->input('period')] === NULL) {
                abort(500, __('This payment period cannot be purchased, please choose another period'));
            }

            if ($request->input('period') === 'reset_price') {
                if (!$userService->isAvailable($user) || $plan->id !== $user->plan_id) {
                    abort(500, __('Subscription has expired or no active subscription, unable to purchase Data Reset Package'));
                }
            }

            if ((!$plan->show && !$plan->renew) || (!$plan->show && $user->plan_id !== $plan->id)) {
                if ($request->input('period') !== 'reset_price') {
                    abort(500, __('This subscription has been sold out, please choose another subscription'));
                }
            }

            if (!$plan->renew && $user->plan_id == $plan->id && $request->input('period') !== 'reset_price') {
                abort(500, __('This subscription cannot be renewed, please change to another subscription'));
            }

            if (!$plan->show && $plan->renew && !$userService->isAvailable($user)) {
                abort(500, __('This subscription has expired, please change to another subscription'));
            }

            $order = new Order();
            $orderService = new OrderService($order);
            $order->user_id = $request->user['id'];
            $order->plan_id = $plan->id;
            $order->period = $request->input('period');
            $order->trade_no = Helper::generateOrderNo();
            $order->total_amount = $plan[$request->input('period')];

            if ($request->input('coupon_code')) {
                $couponService = new CouponService($request->input('coupon_code'));
                if (!$couponService->use($order)) {
                    abort(500, __('Coupon failed'));
                }
                $order->coupon_id = $couponService->getId();
            }

            $orderService->setVipDiscount($user);
            $orderService->setOrderType($user);

            if ($user->balance > 0 && $order->total_amount > 0) {
                $remainingBalance = $user->balance - $order->total_amount;
                $userService = new UserService();
                if ($remainingBalance > 0) {
                    if (!$userService->addBalance($order->user_id, - $order->total_amount)) {
                        abort(500, __('Insufficient balance'));
                    }
                    $order->balance_amount = $order->total_amount;
                    $order->total_amount = 0;
                } else {
                    if (!$userService->addBalance($order->user_id, - $user->balance)) {
                        abort(500, __('Insufficient balance'));
                    }
                    $order->balance_amount = $user->balance;
                    $order->total_amount -= $user->balance;
                }
            }

            $orderService->setInvite($user);

            if (!$order->save()) {
                abort(500, __('Failed to create order'));
            }

            return $order->trade_no;
        }, 3);
        return response([
            'data' => $tradeNo
        ]);
    }

    public function checkout(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $method = $request->input('method');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user['id'])
            ->where('status', 0)
            ->first();
        if (!$order) {
            abort(500, __('Order does not exist or has been paid'));
        }
        // free process
        if ($order->total_amount < 0) abort(500, __('Invalid order amount'));
        if ((int)$order->total_amount === 0) {
            $orderService = new OrderService($order);
            if (!$orderService->paid($order->trade_no)) abort(500, '');
            return response([
                'type' => -1,
                'data' => true
            ]);
        }
        $method = filter_var($method, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $clientToken = $request->input('token');
        if ($method === false || ($clientToken !== null && (!is_string($clientToken) || strlen($clientToken) > 2048))) abort(422, '支付参数无效');
        $attempts = new \App\Services\PaymentAttemptService();
        $returnUrl = \App\Services\PaymentReturnUrl::make($tradeNo);
        $returnContext = ['url' => $returnUrl, 'home' => \App\Services\PaymentReturnUrl::base() . '/'];
        $attempt = $attempts->begin($request->user['id'], $tradeNo, $method, $clientToken, $returnContext);
        if ($attempt->response) return response(json_decode($attempt->response, true, 512, JSON_THROW_ON_ERROR));
        try {
            $paymentService = new PaymentService($attempt->method, $method);
            $attempt = $attempts->quote($attempt, $paymentService);
            $params = ['trade_no' => $attempt->trade_no, 'return_trade_no' => $tradeNo, 'total_amount' => (int)$attempt->total_amount,
                'user_id' => $order->user_id, 'stripe_token' => $clientToken, 'idempotency_key' => 'v2b-' . $attempt->trade_no];
            if ($attempt->gateway_amount_minor !== null) {
                $params['gateway_amount_minor'] = (int)$attempt->gateway_amount_minor;
                $params['gateway_currency'] = $attempt->gateway_currency;
            }
            $result = $paymentService->pay($params);
            $attempts->complete($attempt, $result);
        } catch (\Throwable $error) {
            $attempts->retry($attempt);
            throw $error;
        }
        return response([
            'type' => $result['type'],
            'data' => $result['data']
        ]);
    }

    public function check(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user['id'])
            ->first();
        if (!$order) {
            abort(500, __('Order does not exist'));
        }
        return response([
            'data' => $order->status
        ]);
    }

    public function getPaymentMethod()
    {
        $methods = Payment::select([
            'id',
            'name',
            'payment',
            'icon',
            'handling_fee_fixed',
            'handling_fee_percent'
        ])
            ->where('enable', 1)
            ->orderBy('sort', 'ASC')
            ->get();

        return response([
            'data' => $methods
        ]);
    }

    public function cancel(Request $request)
    {
        if (empty($request->input('trade_no'))) {
            abort(500, __('Invalid parameter'));
        }
        $order = Order::where('trade_no', $request->input('trade_no'))
            ->where('user_id', $request->user['id'])
            ->first();
        if (!$order) {
            abort(500, __('Order does not exist'));
        }
        if ($order->status !== 0) {
            abort(500, __('You can only cancel pending orders'));
        }
        $orderService = new OrderService($order);
        if (!$orderService->cancel()) {
            abort(500, __('Cancel failed'));
        }
        return response([
            'data' => true
        ]);
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
