<?php

namespace Tests\P1;

use App\Http\Controllers\V1\User\OrderController;
use App\Http\Requests\User\OrderSave;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Plan;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DiscountTest extends DatabaseTestCase
{
    public function testFullCouponPlusVipDoesNotMintExtraRefund()
    {
        $old = Plan::create(['onetime_price' => 20000]);
        $target = Plan::create(['month_price' => 10000]);
        $user = $this->user(['plan_id' => $old->id, 'expired_at' => null, 'transfer_enable' => 107374182400, 'discount' => 20]);
        $source = $this->order($user, ['plan_id' => $old->id, 'period' => 'onetime_price', 'type' => 1, 'status' => 3, 'total_amount' => 20000]);
        Coupon::create(['code' => 'full-coupon', 'show' => 1, 'value' => 10000, 'type' => 1, 'started_at' => time() - 60, 'ended_at' => time() + 3600]);
        $r = new OrderSave();
        $r->merge(['user' => ['id' => $user->id], 'plan_id' => $target->id, 'period' => 'month_price', 'coupon_code' => 'full-coupon']);
        (new OrderController())->save($r);
        $order = Order::where('status', 0)->firstOrFail();
        $this->assertSame(10000, (int)$order->discount_amount);
        $this->assertSame(0, (int)$order->total_amount);
        $this->assertSame(20000, (int)$order->refund_amount);
        (new OrderController())->checkout(new Request(['user' => ['id' => $user->id], 'trade_no' => $order->trade_no]));
        (new OrderService($order))->open();
        $this->assertSame(20000, (int)$user->fresh()->balance);
        $this->assertSame(4, (int)$source->fresh()->status);
    }

    public function discountCases()
    {
        return [[10000, 9000, 20, 10000, 0], [10000, 1000, 10, 2000, 8000], [101, 0, 10, 10, 91], [1, 0, 20, 0, 1], [0, 0, 20, 0, 0]];
    }

    /** @dataProvider discountCases */
    public function testDiscountsRemainBoundedIntegerCents($price, $coupon, $vip, $discount, $payable)
    {
        $order = new Order(['total_amount' => $price, 'discount_amount' => $coupon]);
        $user = $this->user(['discount' => $vip]);
        (new OrderService($order))->setVipDiscount($user);
        $this->assertSame($discount, $order->discount_amount);
        $this->assertSame($payable, $order->total_amount);
    }

    public function testNegativeLegacyOrderCannotUseFreeCheckoutOrPaidEntry()
    {
        $user = $this->user();
        $order = $this->order($user, ['total_amount' => -100]);
        $this->assertFalse((new OrderService($order))->paid('free'));
        try {
            (new OrderController())->checkout(new Request(['user' => ['id' => $user->id], 'trade_no' => $order->trade_no]));
            $this->fail('Negative price cannot use free checkout');
        } catch (HttpException $e) {
            $this->assertSame(0, (int)$order->fresh()->status);
            Environment::$queue->assertNothingPushed();
        }
    }

    public function testPreviouslyQueuedExcessRefundIsBlockedBeforeCredit()
    {
        $plan = Plan::create(['month_price' => 10000]);
        $user = $this->user();
        $source = $this->order($user, ['status' => 3, 'total_amount' => 20000]);
        $order = $this->order($user, ['status' => 1, 'plan_id' => $plan->id, 'type' => 3, 'period' => 'month_price', 'total_amount' => 0, 'surplus_amount' => 20000, 'refund_amount' => 22000, 'surplus_order_ids' => [$source->id]]);
        try {
            (new OrderService($order))->open();
            $this->fail('Existing inflated refund must be blocked');
        } catch (HttpException $e) {
            $this->assertSame(0, (int)$user->fresh()->balance);
            $this->assertSame(3, (int)$source->fresh()->status);
            $this->assertSame(1, (int)$order->fresh()->status);
        }
    }

    public function testZeroPricedAuthorizedPlanStillOpens()
    {
        $plan = Plan::create(['month_price' => 0]);
        $user = $this->user();
        $r = new OrderSave();
        $r->merge(['user' => ['id' => $user->id], 'plan_id' => $plan->id, 'period' => 'month_price']);
        (new OrderController())->save($r);
        $order = Order::where('user_id', $user->id)->firstOrFail();
        (new OrderController())->checkout(new Request(['user' => ['id' => $user->id], 'trade_no' => $order->trade_no]));
        (new OrderService($order))->open();
        $this->assertSame(3, (int)$order->fresh()->status);
        $this->assertSame($plan->id, $user->fresh()->plan_id);
        $this->assertSame(0, (int)$user->fresh()->balance);
    }

    public function testForgedSurplusCannotExceedSourcePaidCost()
    {
        $plan = Plan::create(['month_price' => 10000]);
        $user = $this->user();
        $source = $this->order($user, ['status' => 3, 'period' => 'onetime_price', 'total_amount' => 20000]);
        $order = $this->order($user, ['status' => 1, 'plan_id' => $plan->id, 'type' => 3, 'period' => 'month_price', 'total_amount' => 0, 'surplus_amount' => 30000, 'refund_amount' => 22000, 'surplus_order_ids' => [$source->id]]);
        try {
            (new OrderService($order))->open();
            $this->fail('A higher stored surplus does not create paid backing');
        } catch (HttpException $e) {
            $this->assertSame(0, (int)$user->fresh()->balance);
            $this->assertSame(3, (int)$source->fresh()->status);
        }
    }

    public function testFractionalSurplusCannotTruncateRemainingPaymentToZero()
    {
        $old = Plan::create(['onetime_price' => 10000]);
        $target = Plan::create(['month_price' => 10000]);
        $user = $this->user(['plan_id' => $old->id, 'expired_at' => null, 'transfer_enable' => 107374182400, 'd' => 1]);
        $this->order($user, ['plan_id' => $old->id, 'period' => 'onetime_price', 'status' => 3, 'total_amount' => 10000]);
        $r = new OrderSave();
        $r->merge(['user' => ['id' => $user->id], 'plan_id' => $target->id, 'period' => 'month_price']);
        (new OrderController())->save($r);
        $order = Order::where('status', 0)->firstOrFail();
        $this->assertSame(9999, (int)$order->surplus_amount);
        $this->assertSame(1, (int)$order->total_amount);
    }
}
