<?php

namespace Tests\P1;

use App\Http\Controllers\V1\Admin\UserController;
use App\Http\Requests\Admin\UserFetch;
use App\Http\Requests\Admin\UserSendMail;
use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Services\SubscriptionAuditSchema;
use App\Services\SubscriptionAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminUserSubscriptionCountTest extends DatabaseTestCase
{
    private $audit;
    protected function setUp(): void
    {
        parent::setUp();
        config(['v2board.subscribe_url' => 'http://localhost', 'v2board.show_subscribe_method' => 0]);
        Request::macro('validate', function (array $rules, array $messages = []) {
            return app('validator')->make($this->all(), $rules, $messages)->validate();
        });
        $schema = DB::getSchemaBuilder();
        $schema->dropIfExists(SubscriptionAuditService::TABLE);
        $schema->dropIfExists(SubscriptionAuditService::SETTINGS);
        (new SubscriptionAuditSchema())->ensure();
        $this->audit = new SubscriptionAuditService();
    }
    private function account(string $email, int $balance = 0)
    {
        return $this->user(['email' => $email, 'balance' => $balance, 'token' => bin2hex(random_bytes(16))]);
    }
    private function event($user, array $extra = []): array
    {
        return array_merge(['event_id' => bin2hex(random_bytes(16)), 'user_id' => $user->id, 'email' => $user->email,
            'ip' => '192.0.2.10', 'ua' => 'fixture-client', 'format' => 'ClashMeta', 'result' => 'success', 'status' => 200, 'requested_at' => time()], $extra);
    }
    private function pull($user, int $count): void
    {
        for ($i = 0; $i < $count; $i++) $this->audit->append($this->event($user));
    }
    private function fetch(array $input = []): array
    {
        return (new UserController())->fetch(UserFetch::create('/', 'GET', $input))->getData(true);
    }
    private function filter(string $operator, $value): array { return ['key' => 'subscribe_count', 'condition' => $operator, 'value' => $value]; }

    public function testCountsIncludeZeroAndFailedPullsWithoutCrossingUserIdentities(): void
    {
        $a = $this->account('old@example.test'); $b = $this->account('other@example.test');
        $event = $this->event($a); $this->audit->append($event); $this->audit->append($event);
        $this->audit->append($this->event($a, ['result' => 'unavailable', 'status' => 403]));
        $a->email = 'renamed@example.test'; $a->save();
        // Historical email snapshots, including an identical current email, do not identify another user.
        $this->audit->append($this->event($a, ['email' => $b->email]));
        $rows = collect($this->fetch(['sort' => 'id', 'sort_type' => 'ASC'])['data'])->keyBy('id');
        $this->assertSame(3, $rows[$a->id]['subscribe_count']);
        $this->assertSame(0, $rows[$b->id]['subscribe_count']);
        $this->assertSame('renamed@example.test', $rows[$a->id]['email']);
    }
    public function testAllNumericOperatorsAndZeroWorkAcrossTheWholeUserSet(): void
    {
        $a = $this->account('zero@example.test'); $b = $this->account('two@example.test'); $c = $this->account('five@example.test');
        $this->pull($b, 2); $this->pull($c, 5);
        foreach ([['=', 0, [$a->id]], ['>', 2, [$c->id]], ['>=', 2, [$b->id, $c->id]], ['<', 2, [$a->id]], ['<=', 2, [$a->id, $b->id]], ['!=', 0, [$b->id, $c->id]]] as [$operator, $value, $expected]) {
            $response = $this->fetch(['sort' => 'id', 'sort_type' => 'ASC', 'filter' => [$this->filter($operator, $value)]]);
            $this->assertSame($expected, array_column($response['data'], 'id'));
            $this->assertSame(count($expected), $response['total']);
        }
    }
    public function testCountSortingPaginationAndOtherFiltersUseTheSameScope(): void
    {
        $ids = [];
        for ($i = 0; $i < 13; $i++) { $u = $this->account('page-' . $i . '@example.test', 123); $this->pull($u, $i); $ids[] = $u->id; }
        $first = $this->fetch(['sort' => 'subscribe_count', 'sort_type' => 'DESC', 'pageSize' => 10]);
        $second = $this->fetch(['sort' => 'subscribe_count', 'sort_type' => 'DESC', 'pageSize' => 10, 'current' => 2]);
        $this->assertSame(13, $first['total']);
        $this->assertSame(range(12, 3), array_column($first['data'], 'subscribe_count'));
        $this->assertSame([2, 1, 0], array_column($second['data'], 'subscribe_count'));
        $response = $this->fetch(['sort' => 'subscribe_count', 'sort_type' => 'ASC', 'filter' => [$this->filter('>=', 10), ['key' => 'balance', 'condition' => '=', 'value' => 123]], 'ids' => [$ids[11], $ids[12]]]);
        $this->assertSame([$ids[11], $ids[12]], array_column($response['data'], 'id'));
    }
    public function testNoPerUserCountQueriesAreAddedToAnUnfilteredPage(): void
    {
        for ($i = 0; $i < 12; $i++) { $u = $this->account('queries-' . $i . '@example.test'); $this->pull($u, 1); }
        DB::connection()->enableQueryLog();
        try {
            DB::connection()->flushQueryLog(); $this->fetch(['pageSize' => 10]); $ten = count(DB::connection()->getQueryLog());
            DB::connection()->flushQueryLog(); $this->fetch(['pageSize' => 20]); $twenty = count(DB::connection()->getQueryLog());
            $this->assertSame($ten, $twenty);
            $this->assertSame(4, $ten, 'Pagination, users, plans and one aggregate query');
        } finally { DB::connection()->disableQueryLog(); }
    }
    public function testLifetimeFiltersSupportTotalsBeyondThirtyTwoBitIntegers(): void
    {
        $a = $this->account('large-total@example.test'); $this->account('small-total@example.test');
        DB::table(SubscriptionAuditService::TOTALS)->insert(['user_id' => $a->id, 'pull_count' => 3000000000]);
        $response = $this->fetch(['sort' => 'subscribe_count', 'sort_type' => 'DESC', 'filter' => [$this->filter('>=', '3000000000')]]);
        $this->assertSame([$a->id], array_column($response['data'], 'id'));
        $this->assertSame(3000000000, $response['data'][0]['subscribe_count']);
    }
    public function testRetentionAndEntryEvictionDoNotReduceDisplayedLifetimeCounts(): void
    {
        $u = $this->account('prune@example.test'); $this->pull($u, 3);
        $this->audit->configure(30, 2); $this->audit->prune();
        $this->assertSame(3, $this->fetch()['data'][0]['subscribe_count']);
        DB::table(SubscriptionAuditService::TABLE)->update(['requested_at' => time() - 31 * 86400]); $this->audit->prune();
        $this->assertSame(3, $this->fetch()['data'][0]['subscribe_count']);
        $this->assertSame(0, $this->fetch(['filter' => [$this->filter('=', '0')]])['total']);
        $this->assertSame(1, $this->fetch(['filter' => [$this->filter('=', '3')]])['total']);
    }
    public function testExportsAndMassEmailRespectTheCountFilterAndSort(): void
    {
        $a = $this->account('picked@example.test'); $b = $this->account('excluded@example.test'); $this->pull($a, 2);
        $input = ['sort' => 'subscribe_count', 'sort_type' => 'DESC', 'filter' => [$this->filter('>=', 1)]];
        ob_start(); (new UserController())->dumpCSV(Request::create('/', 'POST', $input)); $csv = ob_get_clean();
        $this->assertStringContainsString($a->email, $csv); $this->assertStringNotContainsString($b->email, $csv);
        $request = new UserSendMail(); $request->merge($input + ['subject' => 'fixture', 'content' => 'fixture']);
        (new UserController())->sendMail($request);
        Environment::$queue->assertPushed(SendEmailJob::class, 1);
        $params = new \ReflectionProperty(SendEmailJob::class, 'params'); $params->setAccessible(true);
        Environment::$queue->assertPushed(SendEmailJob::class, fn($job) => $params->getValue($job)['email'] === $a->email);
    }
    public function testBulkBanAcceptsCountSortingAndDoesNotAffectExcludedUsers(): void
    {
        $a = $this->account('ban-picked@example.test'); $b = $this->account('ban-excluded@example.test'); $this->pull($a, 1);
        (new UserController())->ban(Request::create('/', 'POST', ['sort' => 'subscribe_count', 'sort_type' => 'DESC', 'filter' => [$this->filter('>', 0)]]));
        $this->assertSame(1, (int)$a->fresh()->banned); $this->assertSame(0, (int)$b->fresh()->banned);
    }
    public function testMalformedCountFiltersCannotReachQueriesOrBulkMutations(): void
    {
        $u = $this->account('safe@example.test');
        foreach ([['模糊', 1], ['OR 1=1', 0], ['=', -1], ['=', '1.5'], ['=', '0 OR 1=1'], ['=', []], ['=', true], ['=', '9007199254740992']] as [$operator, $value]) {
            try { (new UserController())->ban(Request::create('/', 'POST', ['filter' => [$this->filter($operator, $value)]])); $this->fail('Invalid count filter accepted'); }
            catch (ValidationException $error) { $this->assertNotEmpty($error->errors()); }
        }
        $this->assertSame(0, (int)$u->fresh()->banned);
    }
    public function testAuditUpdatesDuringBulkBanCannotChangeTheSelectedUsers(): void
    {
        $a = $this->account('stable-picked@example.test'); $b = $this->account('stable-kept@example.test'); $this->pull($a, 1);
        $cache = new class(new \Illuminate\Cache\ArrayStore()) extends \Illuminate\Cache\Repository {
            public $afterForget;
            public function forget($key) { $result = parent::forget($key); if ($this->afterForget) ($this->afterForget)($key); return $result; }
        };
        $changed = false;
        $cache->afterForget = function ($key) use ($a, $b, &$changed) {
            if ($changed || $key !== \App\Utils\CacheKey::get('USER_SESSIONS', $a->id)) return;
            $changed = true;
            DB::table(SubscriptionAuditService::TABLE)->delete();
            $this->pull($b, 1);
        };
        \Illuminate\Support\Facades\Cache::swap($cache);
        (new UserController())->ban(Request::create('/', 'POST', ['sort' => 'subscribe_count', 'sort_type' => 'DESC', 'filter' => [$this->filter('>', 0)]]));
        $this->assertTrue($changed);
        $this->assertSame(1, (int)$a->fresh()->banned);
        $this->assertSame(0, (int)$b->fresh()->banned);
    }
    public function testBulkDeletionUsesCountFilterWithoutDeletingAnotherUsersAuditHistory(): void
    {
        foreach (['v2_invite_code' => 'user_id', 'v2_ticket' => 'user_id', 'v2_ticket_message' => 'ticket_id'] as $table => $column) {
            DB::getSchemaBuilder()->dropIfExists($table);
            DB::getSchemaBuilder()->create($table, function (\Illuminate\Database\Schema\Blueprint $t) use ($column) { $t->increments('id'); $t->integer($column); });
        }
        $a = $this->account('delete-picked@example.test'); $b = $this->account('delete-kept@example.test'); $this->pull($a, 2);
        (new UserController())->allDel(Request::create('/', 'POST', ['sort' => 'subscribe_count', 'sort_type' => 'DESC', 'filter' => [$this->filter('=', 2)]]));
        $this->assertNull($a->fresh()); $this->assertNotNull($b->fresh());
        $this->assertSame(2, DB::table(SubscriptionAuditService::TABLE)->where('user_id', $a->id)->count());
        $this->assertSame(0, $this->fetch()['data'][0]['subscribe_count']);
    }
}
