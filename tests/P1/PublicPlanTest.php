<?php

namespace Tests\P1;

use App\Models\Plan;
use App\Services\PublicPlanService;
use App\Http\Controllers\V1\Guest\PlanController;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class PublicPlanTest extends DatabaseTestCase
{
    private function plan(array $values = []): Plan
    {
        return Plan::create(array_merge(['name' => 'Public fixture', 'show' => 1, 'renew' => 1,
            'content' => '<p>Package description</p>', 'month_price' => 1234, 'sort' => 1], $values));
    }

    private function catalog(): array { return (new PublicPlanService())->catalog(); }

    public function testDisabledByDefaultDoesNotReadPlanOrUserTables(): void
    {
        $this->plan();
        DB::enableQueryLog(); DB::flushQueryLog();
        $result = $this->catalog();
        $this->assertFalse($result['meta']['enabled']);
        $this->assertSame([], $result['data']);
        $this->assertSame([], DB::getQueryLog()); DB::disableQueryLog();
    }

    public function testEnabledWithoutASelectionExposesNothing(): void
    {
        $this->plan(); config(['v2board.public_plan_enabled' => 1]);
        $this->assertTrue($this->catalog()['meta']['enabled']);
        $this->assertSame([], $this->catalog()['data']);
    }

    public function testOnlyAllowlistedOnSalePlansArePublicAndSorted(): void
    {
        $first = $this->plan(['name' => 'First', 'sort' => 1]);
        $second = $this->plan(['name' => 'Second', 'sort' => 2]);
        $hidden = $this->plan(['name' => 'Hidden renewal', 'show' => 0, 'renew' => 1]);
        $this->plan(['name' => 'Unselected']);
        config(['v2board.public_plan_enabled' => 1, 'v2board.public_plan_ids' => [$hidden->id, $second->id, $first->id, 999999]]);
        $this->assertSame([$first->id, $second->id], array_column($this->catalog()['data'], 'id'));
    }

    public function testPublicFieldsExcludePermissionsCapacityAndInternalPlanData(): void
    {
        $plan = $this->plan(['group_id' => 777, 'capacity_limit' => 99, 'reset_price' => 101,
            'month_price' => 0, 'year_price' => 12345]);
        config(['v2board.public_plan_enabled' => 1, 'v2board.public_plan_ids' => [$plan->id], 'v2board.currency' => 'USD', 'v2board.currency_symbol' => '$']);
        DB::enableQueryLog();DB::flushQueryLog();
        $result = $this->catalog();
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertSame(PublicPlanService::FIELDS, array_keys($result['data'][0]));
        $this->assertSame(0, $result['data'][0]['month_price']);
        $this->assertSame(12345, $result['data'][0]['year_price']);
        $this->assertNull($result['data'][0]['quarter_price']);
        $this->assertSame('USD', $result['meta']['currency']);
        foreach ($queries as $query) $this->assertStringNotContainsString('v2_user', $query['query']);
    }

    public function testSwitchSelectionAndPlanVisibilityChangesTakeEffectImmediately(): void
    {
        $plan = $this->plan();config(['v2board.public_plan_enabled' => 1, 'v2board.public_plan_ids' => [$plan->id]]);
        $this->assertCount(1, $this->catalog()['data']);
        $plan->show = 0;$plan->save();$this->assertSame([], $this->catalog()['data']);
        $plan->show = 1;$plan->save();$this->assertCount(1, $this->catalog()['data']);
        config(['v2board.public_plan_ids' => []]);$this->assertSame([], $this->catalog()['data']);
        config(['v2board.public_plan_ids' => [$plan->id], 'v2board.public_plan_enabled' => 0]);$this->assertSame([], $this->catalog()['data']);
    }

    public function testMalformedStoredSelectionFailsClosed(): void
    {
        $plan = $this->plan();config(['v2board.public_plan_enabled' => 1]);
        foreach (['all', [$plan->id, ['id' => $plan->id]], [true], [0], range(1, 101)] as $selection) {
            config(['v2board.public_plan_ids' => $selection]);$this->assertSame([], $this->catalog()['data']);
        }
    }

    public function testDeletedAndRemovedPlansStayUnavailable(): void
    {
        $plan = $this->plan();config(['v2board.public_plan_enabled' => 1, 'v2board.public_plan_ids' => [$plan->id]]);
        $plan->delete();$this->assertSame([], $this->catalog()['data']);
    }

    public function testAnonymousResponseDisallowsCaching(): void
    {
        $response = (new PlanController())->fetch(new PublicPlanService());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->getData(true)['data']);
    }

    public function testGuestRouteHasDedicatedThrottleAndNoUserMiddleware(): void
    {
        $router = new \Illuminate\Routing\Router(new \Illuminate\Events\Dispatcher(app()), app());
        $router->group(['namespace' => 'App\\Http\\Controllers'], function () use ($router) {
            (new \App\Http\Routes\V1\GuestRoute())->map($router);
        });
        $route = $router->getRoutes()->match(Request::create('/guest/plan/fetch', 'GET'));
        $this->assertContains('throttle:60,1,public-plans', $route->gatherMiddleware());
        $this->assertNotContains('user', $route->gatherMiddleware());
        $this->assertSame('App\\Http\\Controllers\\V1\\Guest\\PlanController@fetch', $route->getActionName());
    }

    public function testAdministratorSelectionValidationRejectsUnknownOrInvalidIds(): void
    {
        $plan = $this->plan();
        $factory = app('validator');
        $factory->setPresenceVerifier(new \Illuminate\Validation\DatabasePresenceVerifier(app('db')));
        $request = new \App\Http\Requests\Admin\ConfigSave();
        foreach ([[999999], [-1], ['not-an-id'], [$plan->id, $plan->id]] as $ids) {
            $this->assertTrue($factory->make(['public_plan_ids' => $ids], $request->rules())->fails());
        }
        $this->assertFalse($factory->make(['public_plan_enabled' => 1, 'public_plan_ids' => [$plan->id]], $request->rules())->fails());
    }

    public function testPublicPlanSettingsBelongToSubscribeAndKeepStoredValues(): void
    {
        config(['v2board.public_plan_enabled' => 1, 'v2board.public_plan_ids' => [123]]);
        $controller = new \App\Http\Controllers\V1\Admin\ConfigController();
        $data = $controller->fetch(Request::create('/config/fetch'))->getData(true)['data'];
        $this->assertArrayNotHasKey('public_plan_enabled', $data['site']);
        $this->assertArrayNotHasKey('public_plan_ids', $data['site']);
        $this->assertSame(1, $data['subscribe']['public_plan_enabled']);
        $this->assertSame([123], $data['subscribe']['public_plan_ids']);
        $filtered = $controller->fetch(Request::create('/config/fetch', 'GET', ['key' => 'subscribe']))->getData(true)['data'];
        $this->assertSame($data['subscribe'], $filtered['subscribe']);
    }
}
