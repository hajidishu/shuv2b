<?php

namespace Tests\P1;

use App\Console\Commands\CheckCommission;
use App\Http\Controllers\V1\User\OrderController;
use App\Http\Requests\User\OrderSave;
use App\Http\Requests\User\UserTransfer;
use App\Jobs\OrderHandleJob;
use App\Models\CommissionLog;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BillingTest extends DatabaseTestCase
{
    public function testDuplicateDepositJobsCreditOnlyOnce()
    {
        $user = $this->user();
        $order = $this->order($user, ['status' => 1]);
        $stale = new OrderService(Order::find($order->id));
        (new OrderHandleJob($order->trade_no))->handle();
        $stale->open();
        (new OrderHandleJob($order->trade_no))->handle();
        $this->assertSame(10000, (int)$user->fresh()->balance);
        $this->assertSame(3, (int)$order->fresh()->status);
    }

    public function testDuplicateRenewalDoesNotExtendTwice()
    {
        $plan = Plan::create(['month_price' => 10000]);
        $expiry = strtotime('+3 days');
        $user = $this->user(['plan_id' => $plan->id, 'expired_at' => $expiry]);
        $order = $this->order($user, ['status' => 1, 'type' => 2, 'plan_id' => $plan->id, 'period' => 'month_price']);
        $first = new OrderService($order);
        $stale = new OrderService(Order::find($order->id));
        $first->open(); $stale->open();
        $this->assertSame(strtotime('+1 month', $expiry), (int)$user->fresh()->expired_at);
    }

    public function testCancellationWinsOverStalePaymentAndRefundsOnce()
    {
        $user = $this->user();
        $order = $this->order($user, ['total_amount' => 0, 'balance_amount' => 10000]);
        $payment = new OrderService(Order::find($order->id));
        $cancel = new OrderService(Order::find($order->id));
        $this->assertTrue($cancel->cancel());
        $this->assertFalse($payment->paid('balance-payment'));
        $this->assertFalse($cancel->cancel());
        $this->assertSame(10000, (int)$user->fresh()->balance);
        $this->assertSame(2, (int)$order->fresh()->status);
        Environment::$queue->assertNothingPushed();
    }

    public function testPaymentWinsOverStaleCancellation()
    {
        $user = $this->user();
        $order = $this->order($user, ['total_amount' => 0, 'balance_amount' => 10000]);
        $cancel = new OrderService(Order::find($order->id));
        $payment = new OrderService($order);
        $this->assertTrue($payment->paid('balance-payment'));
        $this->assertFalse($cancel->cancel());
        $this->assertTrue($payment->paid('duplicate'));
        $this->assertSame(0, (int)$user->fresh()->balance);
        $this->assertSame(1, (int)$order->fresh()->status);
        Environment::$queue->assertPushed(OrderHandleJob::class, 1);
    }

    public function testDispatchWaitsForOuterCommit()
    {
        $order = $this->order($this->user());
        DB::beginTransaction();
        $this->assertTrue((new OrderService($order))->paid('payment'));
        Environment::$queue->assertNothingPushed();
        DB::commit();
        Environment::$queue->assertPushed(OrderHandleJob::class, 1);
    }

    public function testOuterRollbackDoesNotDispatchOrMarkPaid()
    {
        $order = $this->order($this->user());
        DB::beginTransaction();
        $this->assertTrue((new OrderService($order))->paid('payment'));
        DB::rollBack();
        Environment::$queue->assertNothingPushed();
        $this->assertSame(0, (int)$order->fresh()->status);
    }

    public function testOnlyOnePendingOrderCanBeCreated()
    {
        $user = $this->user();
        $r = new OrderSave();
        $r->merge(['user' => ['id' => $user->id], 'plan_id' => 0, 'period' => 'deposit', 'deposit_amount' => 10000]);
        (new OrderController())->save($r);
        try {
            (new OrderController())->save($r);
            $this->fail('Second pending order should be rejected');
        } catch (HttpException $e) {
            $this->assertSame(1, Order::where('user_id', $user->id)->count());
            $this->assertSame(0, DB::transactionLevel());
        }
    }

    public function testNormalCouponPurchaseWithPartialBalanceStillOpens()
    {
        $plan = Plan::create(['month_price' => 10000]);
        $user = $this->user(['balance' => 3000, 'discount' => 10]);
        $coupon = Coupon::create(['code' => 'test-coupon', 'show' => 1, 'type' => 1, 'value' => 1000, 'limit_use' => 2, 'started_at' => time() - 60, 'ended_at' => time() + 3600]);
        $r = new OrderSave();
        $r->merge(['user' => ['id' => $user->id], 'plan_id' => $plan->id, 'period' => 'month_price', 'coupon_code' => 'test-coupon']);
        (new OrderController())->save($r);
        $order = Order::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(5000, (int)$order->total_amount);
        $this->assertSame(3000, (int)$order->balance_amount);
        $this->assertSame(0, (int)$user->fresh()->balance);
        $this->assertSame(1, (int)$coupon->fresh()->limit_use);
        $this->assertTrue((new OrderService($order))->paid('valid-payment'));
        (new OrderService($order))->open();
        $this->assertSame($plan->id, $user->fresh()->plan_id);
        $this->assertSame(3, (int)$order->fresh()->status);
    }

    public function testNormalBalancePurchaseCancellationReturnsReservedFunds()
    {
        $plan = Plan::create(['month_price' => 10000]);
        $user = $this->user(['balance' => 15000]);
        $r = new OrderSave();
        $r->merge(['user' => ['id' => $user->id], 'plan_id' => $plan->id, 'period' => 'month_price']);
        (new OrderController())->save($r);
        $order = Order::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(0, (int)$order->total_amount);
        $this->assertSame(5000, (int)$user->fresh()->balance);
        $this->assertTrue((new OrderService($order))->cancel());
        $this->assertSame(15000, (int)$user->fresh()->balance);
        $this->assertSame(2, (int)$order->fresh()->status);
    }

    public function testSurplusCannotBeRefundedByTwoOrders()
    {
        $plan = Plan::create(['month_price' => 5000]);
        $user = $this->user();
        $source = $this->order($user, ['status' => 3, 'period' => 'onetime_price', 'type' => 1, 'total_amount' => 20000]);
        $values = ['status' => 1, 'type' => 3, 'plan_id' => $plan->id, 'period' => 'month_price', 'total_amount' => 0, 'surplus_amount' => 20000, 'refund_amount' => 15000, 'surplus_order_ids' => [$source->id]];
        $first = $this->order($user, $values);
        $second = $this->order($user, $values);
        (new OrderService($first))->open();
        try {
            (new OrderService($second))->open();
            $this->fail('Consumed source must be rejected');
        } catch (HttpException $e) {
            $this->assertSame(15000, (int)$user->fresh()->balance);
            $this->assertSame(4, (int)$source->fresh()->status);
            $this->assertSame(1, (int)$second->fresh()->status);
        }
    }

    public function testSurplusBelongingToAnotherUserCannotBeConsumed()
    {
        $plan = Plan::create(['month_price' => 5000]);
        $user = $this->user();
        $source = $this->order($this->user(), ['status' => 3, 'period' => 'onetime_price', 'type' => 1, 'total_amount' => 20000]);
        $order = $this->order($user, ['status' => 1, 'type' => 3, 'plan_id' => $plan->id, 'period' => 'month_price', 'total_amount' => 0, 'surplus_amount' => 20000, 'refund_amount' => 15000, 'surplus_order_ids' => [$source->id]]);
        try {
            (new OrderService($order))->open();
            $this->fail('Foreign source must be rejected');
        } catch (HttpException $e) {
            $this->assertSame(0, (int)$user->fresh()->balance);
            $this->assertSame(3, (int)$source->fresh()->status);
        }
    }

    public function testOpeningFailureRollsBackBalanceAndSourceConsumption()
    {
        $plan = Plan::create(['month_price' => 5000]);
        $user = $this->user();
        $source = $this->order($user, ['status' => 3, 'period' => 'onetime_price', 'type' => 1, 'total_amount' => 20000]);
        $order = $this->order($user, ['status' => 1, 'type' => 3, 'plan_id' => $plan->id, 'period' => 'month_price', 'total_amount' => 0, 'surplus_amount' => 20000, 'refund_amount' => 15000, 'surplus_order_ids' => [$source->id]]);
        Order::saving(function ($model) use ($order) {
            if ($model->id === $order->id && (int)$model->status === 3) throw new \RuntimeException('injected save failure');
        });
        try {
            (new OrderService($order))->open();
            $this->fail('Failure should abort opening');
        } catch (\RuntimeException $e) {
            $this->assertSame(0, (int)$user->fresh()->balance);
            $this->assertSame(3, (int)$source->fresh()->status);
            $this->assertSame(1, (int)$order->fresh()->status);
            $this->assertSame(0, DB::transactionLevel());
        }
    }

    public function testCommissionIsPaidOnceAndSeparateOrdersAccumulate()
    {
        $inviter = $this->user();
        $buyer = $this->user();
        $order = $this->order($buyer, ['status' => 3, 'commission_status' => 1, 'invite_user_id' => $inviter->id, 'commission_balance' => 1000]);
        $command = new CheckCommission();
        $command->autoPayCommission(); $command->autoPayCommission();
        $this->assertSame(1000, (int)$inviter->fresh()->commission_balance);
        $this->assertSame(1, CommissionLog::count());
        $this->assertSame(2, (int)$order->fresh()->commission_status);
        $this->order($buyer, ['status' => 3, 'commission_status' => 1, 'invite_user_id' => $inviter->id, 'commission_balance' => 2000]);
        $command->autoPayCommission();
        $this->assertSame(3000, (int)$inviter->fresh()->commission_balance);
        $this->assertSame(2, CommissionLog::count());
    }

    public function testCommissionLogFailureRollsBackPayoutAndCanRetry()
    {
        $inviter = $this->user();
        $order = $this->order($this->user(), ['status' => 3, 'commission_status' => 1, 'invite_user_id' => $inviter->id, 'commission_balance' => 1000]);
        CommissionLog::creating(function () { throw new \RuntimeException('injected log failure'); });
        (new CheckCommission())->autoPayCommission();
        $this->assertSame(0, (int)$inviter->fresh()->commission_balance);
        $this->assertSame(1, (int)$order->fresh()->commission_status);
        CommissionLog::flushEventListeners();
        (new CheckCommission())->autoPayCommission();
        $this->assertSame(1000, (int)$inviter->fresh()->commission_balance);
        $this->assertSame(1, CommissionLog::count());
    }

    public function testInternalTransferDoesNotCreateUpstreamCommission()
    {
        $inviter = $this->user(['commission_type' => 1, 'commission_rate' => 10]);
        $user = $this->user(['invite_user_id' => $inviter->id, 'commission_balance' => 10000]);
        $request = new UserTransfer();
        $request->merge(['user' => ['id' => $user->id], 'transfer_amount' => 10000]);
        (new \App\Http\Controllers\V1\User\UserController())->transfer($request);
        $order = Order::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(10000, (int)$user->fresh()->balance);
        $this->assertSame(0, (int)$user->fresh()->commission_balance);
        $this->assertSame(0, (int)$order->commission_balance);
        $this->assertSame(3, (int)$order->commission_status);
        (new CheckCommission())->autoPayCommission();
        $this->assertSame(0, (int)$inviter->fresh()->commission_balance);
        $this->assertSame(0, CommissionLog::count());
    }

    public function testHistoricalUnpaidTransferCommissionIsRejected()
    {
        $inviter = $this->user();
        $order = $this->order($this->user(), ['total_amount' => 0, 'period' => 'deposit', 'status' => 3, 'commission_status' => 1, 'invite_user_id' => $inviter->id, 'commission_balance' => 1000]);
        (new CheckCommission())->autoPayCommission();
        $this->assertSame(3, (int)$order->fresh()->commission_status);
        $this->assertSame(0, (int)$inviter->fresh()->commission_balance);
        $this->assertSame(0, CommissionLog::count());
    }
}
