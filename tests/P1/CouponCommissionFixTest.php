<?php

namespace Tests\P1;

use App\Console\Commands\CheckCommission;
use App\Models\CommissionLog;
use App\Models\Coupon;
use App\Services\CouponService;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;

class CouponCommissionFixTest extends DatabaseTestCase
{
    public function testZeroShareStillAdvancesToNextInviterLevel()
    {
        config([
            'v2board.commission_distribution_enable' => 1,
            'v2board.commission_distribution_l1' => 0,
            'v2board.commission_distribution_l2' => 50,
            'v2board.commission_distribution_l3' => 0,
            'v2board.withdraw_close_enable' => 0,
        ]);

        $thirdLevel = $this->user();
        $secondLevel = $this->user(['invite_user_id' => $thirdLevel->id]);
        $firstLevel = $this->user(['invite_user_id' => $secondLevel->id]);
        $buyer = $this->user();
        $order = $this->order($buyer, [
            'status' => 3,
            'commission_status' => 1,
            'invite_user_id' => $firstLevel->id,
            'commission_balance' => 1000,
        ]);

        $paid = DB::transaction(function () use ($order, $firstLevel) {
            return (new CheckCommission())->payHandle($firstLevel->id, $order);
        });

        $this->assertTrue($paid);
        $this->assertSame(0, (int)$firstLevel->fresh()->commission_balance);
        $this->assertSame(500, (int)$secondLevel->fresh()->commission_balance);
        $this->assertSame(0, (int)$thirdLevel->fresh()->commission_balance);
        $this->assertSame($secondLevel->id, (int)CommissionLog::firstOrFail()->invite_user_id);
        $this->assertSame(500, (int)CommissionLog::firstOrFail()->get_amount);
    }

    public function testCancelRestoresFiniteCouponUseOnlyOnce()
    {
        $user = $this->user(['balance' => 2000]);
        $coupon = $this->coupon(['limit_use' => 2]);
        $order = $this->order($user, ['status' => 0, 'balance_amount' => 1500]);

        $couponService = new CouponService($coupon->code);
        $this->assertTrue($couponService->use($order));
        $order->coupon_id = $coupon->id;
        $this->assertTrue($order->save());
        $this->assertSame(1, (int)$coupon->fresh()->limit_use);

        $this->assertTrue((new OrderService($order))->cancel());
        $this->assertSame(2, (int)$coupon->fresh()->limit_use);
        $this->assertSame(3500, (int)$user->fresh()->balance);

        $this->assertFalse((new OrderService($order->fresh()))->cancel());
        $this->assertSame(2, (int)$coupon->fresh()->limit_use);
    }

    public function testCancelDoesNotChangeUnlimitedCouponUse()
    {
        $user = $this->user();
        $coupon = $this->coupon(['limit_use' => null]);
        $order = $this->order($user, ['status' => 0, 'coupon_id' => $coupon->id]);

        $this->assertTrue((new OrderService($order))->cancel());
        $this->assertNull($coupon->fresh()->limit_use);
    }

    private function coupon(array $values = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => uniqid('coupon-'),
            'show' => 1,
            'type' => 1,
            'value' => 1000,
            'limit_use' => 2,
            'started_at' => time() - 60,
            'ended_at' => time() + 3600,
        ], $values));
    }
}
