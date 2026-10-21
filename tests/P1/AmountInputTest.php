<?php

namespace Tests\P1;

use App\Http\Requests\User\OrderSave;
use App\Http\Requests\Admin\OrderAssign;
use App\Http\Requests\Admin\PlanSave;
use Illuminate\Validation\Factory;

class AmountInputTest extends DatabaseTestCase
{
    private function validate($request, $input)
    {
        return (new Factory(Environment::$app['translator'], Environment::$app))->make($input, $request->rules());
    }

    public function invalidDeposits()
    {
        return [[null], [0], [-1], ['1e2'], ['1.5'], [[]], [9999999]];
    }

    /** @dataProvider invalidDeposits */
    public function testDepositMustBePositiveIntegerCents($amount)
    {
        $validator = $this->validate(new OrderSave(), ['plan_id' => 0, 'period' => 'deposit', 'deposit_amount' => $amount]);
        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('deposit_amount'));
    }

    public function testValidDepositAndSubscriptionInputsRemainAccepted()
    {
        $this->assertTrue($this->validate(new OrderSave(), ['plan_id' => 0, 'period' => 'deposit', 'deposit_amount' => 1])->passes());
        $this->assertTrue($this->validate(new OrderSave(), ['plan_id' => 1, 'period' => 'month_price', 'deposit_amount' => null])->passes());
    }

    public function testNegativePlanPriceAndManualOrderAmountAreRejected()
    {
        $plan = $this->validate(new PlanSave(), ['name' => 'plan', 'group_id' => 1, 'transfer_enable' => 100, 'month_price' => -1]);
        $this->assertTrue($plan->errors()->has('month_price'));
        $order = $this->validate(new OrderAssign(), ['email' => 'user@example.test', 'plan_id' => 1, 'period' => 'month_price', 'total_amount' => -1]);
        $this->assertTrue($order->errors()->has('total_amount'));
    }
}
