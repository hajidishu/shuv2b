<?php
namespace Tests\P1;

use App\Http\Controllers\V1\Admin\OrderController;
use App\Http\Requests\Admin\OrderFetch;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Validation\Factory;

class AdminOrderFilterTest extends DatabaseTestCase
{
    private function filtered(array $filters)
    {
        $builder = Order::query();
        $method = new \ReflectionMethod(OrderController::class, 'filter');
        $method->setAccessible(true);
        $method->invokeArgs(new OrderController(), [Request::create('/orders', 'GET', ['filter' => $filters]), &$builder]);
        return $builder->orderBy('id')->pluck('id')->all();
    }

    public function testEmailSubstringMatchesEveryUserAndIntersectsOtherFilters()
    {
        $a = $this->user(['email' => 'audit-alpha@example.test']);
        $b = $this->user(['email' => 'audit-beta@example.test']);
        $c = $this->user(['email' => 'other@example.test']);
        $first = $this->order($a, ['status' => 3]);
        $second = $this->order($b, ['status' => 3]);
        $this->order($b, ['status' => 0]);
        $this->order($c, ['status' => 3]);
        $this->assertSame([$first->id, $second->id], $this->filtered([
            ['key' => 'email', 'condition' => '模糊', 'value' => 'audit-'],
            ['key' => 'status', 'condition' => '=', 'value' => 3],
        ]));
    }

    public function testMissingEmailReturnsNoOrdersInsteadOfDroppingFilter()
    {
        $this->order($this->user());
        $this->assertSame([], $this->filtered([['key' => 'email', 'condition' => '模糊', 'value' => 'missing-address']]));
    }

    public function testExactAndNotEqualEmailRespectComparison()
    {
        $a = $this->user(['email' => 'alpha@example.test']);
        $b = $this->user(['email' => 'alpha2@example.test']);
        $first = $this->order($a); $second = $this->order($b);
        $this->assertSame([$first->id], $this->filtered([['key' => 'email', 'condition' => '=', 'value' => $a->email]]));
        $this->assertSame([$second->id], $this->filtered([['key' => 'email', 'condition' => '!=', 'value' => $a->email]]));
    }

    public function testZeroCommissionAndOrderTypeCanBeCombined()
    {
        $user = $this->user();
        $order = $this->order($user, ['type' => 1, 'commission_balance' => 0]);
        $this->order($user, ['type' => 2, 'commission_balance' => 0]);
        $this->order($user, ['type' => 1, 'commission_balance' => 100]);
        $this->assertSame([$order->id], $this->filtered([
            ['key' => 'type', 'condition' => '=', 'value' => 1],
            ['key' => 'commission_balance', 'condition' => '=', 'value' => 0],
        ]));
    }

    public function testFetchAllowsTypeAndZeroButRejectsInvalidFilterShapes()
    {
        $factory = new Factory(Environment::$app['translator'], Environment::$app);
        $rules = (new OrderFetch())->rules();
        $this->assertTrue($factory->make(['filter' => [['key' => 'type', 'condition' => '=', 'value' => 0]]], $rules)->passes());
        foreach ([
            ['filter' => [['key' => 'password', 'condition' => '=', 'value' => 'x']]],
            ['filter' => [['key' => 'status', 'condition' => 'OR 1=1', 'value' => 0]]],
            ['filter' => [['key' => 'status', 'condition' => '=', 'value' => ['x']]]],
            ['pageSize' => 999999],
        ] as $input) $this->assertTrue($factory->make($input, $rules)->fails());
    }
}
