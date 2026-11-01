<?php

namespace Tests\P1;

use App\Http\Controllers\V1\Admin\UserController;
use App\Http\Requests\Admin\UserFetch;
use App\Services\SubscriptionAuditSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AdminOnlineDeviceSortTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['v2board.subscribe_url' => 'https://subscribe.fixture.test',
            'v2board.app_url' => 'https://panel.fixture.test',
            'v2board.show_subscribe_method' => 0]);
        foreach (['v2_subscribe_audit_count_event', 'v2_subscribe_audit_total',
                  'v2_subscribe_audit_setting', 'v2_subscribe_audit'] as $table) {
            DB::getSchemaBuilder()->dropIfExists($table);
        }
        (new SubscriptionAuditSchema())->ensure();
        Request::macro('validate', function (array $rules, array $messages = []) {
            return app('validator')->make($this->all(), $rules, $messages)->validate();
        });
    }

    private function fetch(array $input = []): array
    {
        return (new UserController())->fetch(UserFetch::create('/', 'GET', $input))->getData(true);
    }

    private function setOnlineCount($user, int $count): void
    {
        Cache::put('ALIVE_IP_USER_' . $user->id, ['alive_ip' => $count], 3600);
    }

    public function testDescendingSortUsesAllFilteredUsersBeforePaginationAndIgnoresDeviceLimit()
    {
        $users = [];
        for ($online = 0; $online < 25; $online++) {
            $users[$online] = $this->user([
                'device_limit' => 30 - $online,
                'balance' => $online % 2,
                'email' => 'device-sort-' . $online . '@example.test',
            ]);
            $this->setOnlineCount($users[$online], $online);
        }

        $first = $this->fetch(['sort' => 'alive_ip', 'sort_type' => 'DESC', 'pageSize' => 10]);
        $this->assertSame(25, $first['total']);
        $this->assertSame(range(24, 15), array_column($first['data'], 'alive_ip'));
        $this->assertSame(array_map(function ($i) use ($users) { return $users[$i]->id; }, range(24, 15)), array_column($first['data'], 'id'));
        $this->assertSame(24, $first['data'][0]['alive_ip']);
        $this->assertSame(6, (int)$first['data'][0]['device_limit'], 'Online count must win over the deliberately inverse device limit');

        $second = $this->fetch(['sort' => 'alive_ip', 'sort_type' => 'DESC', 'pageSize' => 10, 'current' => 2]);
        $this->assertSame(25, $second['total']);
        $this->assertSame(range(14, 5), array_column($second['data'], 'alive_ip'));

        $filtered = $this->fetch(['sort' => 'alive_ip', 'sort_type' => 'DESC', 'pageSize' => 10,
            'current' => 2, 'filter' => [['key' => 'balance', 'condition' => '=', 'value' => 1]]]);
        $this->assertSame(12, $filtered['total']);
        $this->assertSame([3, 1], array_column($filtered['data'], 'alive_ip'));

        $filtered = $this->fetch(['sort' => 'alive_ip', 'sort_type' => 'DESC', 'pageSize' => 10,
            'filter' => [['key' => 'device_limit', 'condition' => '>=', 'value' => 20]]]);
        $this->assertSame(11, $filtered['total']);
        $this->assertSame(range(10, 1), array_column($filtered['data'], 'alive_ip'));
        $lastFilteredPage = $this->fetch(['sort' => 'alive_ip', 'sort_type' => 'DESC', 'pageSize' => 10,
            'current' => 2, 'filter' => [['key' => 'device_limit', 'condition' => '>=', 'value' => 20]]]);
        $this->assertSame(11, $lastFilteredPage['total']);
        $this->assertSame([0], array_column($lastFilteredPage['data'], 'alive_ip'));
    }

    public function testAscendingSortTreatsMissingCacheAsZeroAndBreaksTiesById()
    {
        $zeroA = $this->user(['email' => 'alive-zero-a@example.test']);
        $zeroB = $this->user(['email' => 'alive-zero-b@example.test']);
        $twoA = $this->user(['email' => 'alive-two-a@example.test']);
        $twoB = $this->user(['email' => 'alive-two-b@example.test']);
        $this->setOnlineCount($zeroA, 0);
        // zeroB intentionally has no cache entry and must sort as count zero.
        $this->setOnlineCount($twoA, 2);
        $this->setOnlineCount($twoB, 2);

        $ascending = $this->fetch(['sort' => 'alive_ip', 'sort_type' => 'ASC']);
        $this->assertSame([$zeroA->id, $zeroB->id, $twoA->id, $twoB->id], array_column($ascending['data'], 'id'));
        $this->assertSame([0, 0, 2, 2], array_column($ascending['data'], 'alive_ip'));
        $this->assertSame(4, $ascending['total']);

        $descending = $this->fetch(['sort' => 'alive_ip', 'sort_type' => 'DESC']);
        $this->assertSame([$twoA->id, $twoB->id, $zeroA->id, $zeroB->id], array_column($descending['data'], 'id'));
        $this->assertSame([2, 2, 0, 0], array_column($descending['data'], 'alive_ip'));
    }

    public function testUserFetchAllowsAliveIpSortAndRejectsInjectedSortValues()
    {
        $validator = app('validator');
        $allowed = $validator->make(['sort' => 'alive_ip', 'sort_type' => 'DESC'], (new UserFetch())->rules());
        $this->assertTrue($allowed->passes());

        foreach (['alive_ip desc', 'alive_ip, (select 1)', 'password', 'id; DROP TABLE v2_user'] as $sort) {
            $invalid = $validator->make(['sort' => $sort], (new UserFetch())->rules());
            $this->assertTrue($invalid->fails(), 'Unapproved sort value must fail validation: ' . $sort);
        }
    }
}
