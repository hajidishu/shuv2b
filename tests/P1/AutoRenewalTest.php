<?php

namespace Tests\P1;

use App\Console\Commands\CheckRenewal;
use App\Models\Order;
use App\Models\Plan;

class AutoRenewalTest extends DatabaseTestCase
{
    private function subscriber($price = 10000, $balance = 20000, $discount = 0)
    {
        $plan = Plan::create(['month_price' => $price]);
        $user = $this->user(['plan_id' => $plan->id, 'auto_renewal' => 1, 'expired_at' => time() + 86400, 'balance' => $balance, 'discount' => $discount]);
        $this->order($user, ['plan_id' => $plan->id, 'period' => 'month_price', 'type' => 1, 'status' => 3]);
        return [$user, $plan];
    }

    public function testRenewalUsesDiscountedPriceAndDoesNotRepeat()
    {
        list($user, $plan) = $this->subscriber(10000, 20000, 20);
        $expiry = $user->expired_at;
        (new CheckRenewal())->handle();
        (new CheckRenewal())->handle();
        $this->assertSame(12000, (int)$user->fresh()->balance);
        $this->assertSame(strtotime('+1 month', $expiry), (int)$user->fresh()->expired_at);
        $this->assertSame(2, Order::where('user_id', $user->id)->count());
        $this->assertSame(8000, (int)Order::orderBy('id', 'desc')->first()->balance_amount);
    }

    public function disabledRenewalCases()
    {
        return [[null, 20000], [-1, 20000], [10000, 5000]];
    }

    /** @dataProvider disabledRenewalCases */
    public function testUnavailablePeriodOrInsufficientFundsCannotRenew($price, $balance)
    {
        list($user, $plan) = $this->subscriber($price, $balance);
        $expiry = $user->expired_at;
        (new CheckRenewal())->handle();
        $this->assertSame($balance, (int)$user->fresh()->balance);
        $this->assertSame($expiry, (int)$user->fresh()->expired_at);
        $this->assertSame(0, (int)$user->fresh()->auto_renewal);
        $this->assertSame(1, Order::count());
    }

    public function testRenewalOrderFailureRollsBackDebitAndExpiry()
    {
        list($user, $plan) = $this->subscriber();
        $expiry = $user->expired_at;
        Order::creating(function () { throw new \RuntimeException('injected renewal failure'); });
        (new CheckRenewal())->handle();
        $this->assertSame(20000, (int)$user->fresh()->balance);
        $this->assertSame($expiry, (int)$user->fresh()->expired_at);
        $this->assertSame(1, Order::count());
    }
}
