<?php
namespace Tests\P1;

use App\Services\LeakProbeSchema;
use App\Services\LeakProbeService as Probe;
use App\Services\ServerService;
use App\Models\ServerVmess;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LeakProbeTest extends DatabaseTestCase
{
    private $probe; private $users; private $nodes; private $plan;
    protected function setUp(): void
    {
        parent::setUp();
        $schema = DB::connection()->getSchemaBuilder();
        foreach (['event','active_user','member','round','task','node'] as $suffix) $schema->dropIfExists('v2_leak_probe_' . $suffix);
        (new LeakProbeSchema())->ensure();
        foreach (Probe::TYPES as $type) {
            $schema->dropIfExists('v2_server_' . $type);
            $schema->create('v2_server_' . $type, function (Blueprint $t) {
                $t->increments('id');
                foreach (['name','host','port','network','cipher','protocol'] as $field) $t->string($field)->nullable();
                foreach (['group_id','route_id','tags','networkSettings','tlsSettings','network_settings','tls_settings','encryption_settings','ruleSettings','dnsSettings'] as $field) $t->text($field)->nullable();
                foreach (['server_port','tls','show','sort','parent_id','created_at','updated_at'] as $field) $t->integer($field)->nullable();
                $t->decimal('rate', 10, 3)->default(1);
            });
        }
        Environment::$app->instance('cache', new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore()));
        Environment::$app->instance('files', new \Illuminate\Filesystem\Filesystem());
        Cache::clearResolvedInstance('cache');
        $root = getenv('P1_SOURCE_ROOT') ?: dirname(__DIR__, 2);
        Environment::$app->setBasePath($root);
        $this->probe = new Probe();
        $this->plan = Plan::create(['name' => 'probe-plan', 'group_id' => 1]);
        $this->users = collect();
        for ($i = 0; $i < 4; $i++) $this->users->push($this->user(['email' => 'probe-' . $i . '@example.test', 'plan_id' => $this->plan->id, 'group_id' => 1,
            'transfer_enable' => 100000, 'expired_at' => time() + 86400, 'uuid' => '11111111-1111-4111-8111-11111111111' . $i, 'token' => 'token-' . $i, 'balance' => 1999]));
        $this->nodes = [];
        for ($i = 1; $i <= 4; $i++) {
            $node = $this->node('probe-' . $i, '203.0.113.' . $i);
            $this->nodes[] = $this->probe->registerNode('vmess', $node->id);
        }
        $this->node('ordinary', '198.51.100.1');
    }
    protected function tearDown(): void
    {
        foreach (['event','active_user','member','round','task','node'] as $suffix) DB::connection()->getSchemaBuilder()->dropIfExists('v2_leak_probe_' . $suffix);
        parent::tearDown();
    }
    private function node($name, $host)
    {
        return ServerVmess::create(['name' => $name, 'host' => $host, 'port' => '443', 'server_port' => 443, 'network' => 'tcp', 'networkSettings' => [],
            'tls' => 0, 'tlsSettings' => [], 'group_id' => [1], 'route_id' => [], 'tags' => [], 'show' => 1, 'rate' => 1, 'sort' => 1]);
    }
    private function create($extra = [])
    {
        $scope = ['scope' => 'plans', 'plan_ids' => [$this->plan->id]];
        $preview = $this->probe->preview($scope);
        return $this->probe->create(array_merge($scope, ['name' => 'unit-probe', 'observation_hours' => 24, 'a_node_id' => $this->nodes[0], 'b_node_id' => $this->nodes[1],
            'expected_count' => $preview['count'], 'expected_hash' => $preview['hash']], $extra), 'admin@example.test');
    }
    private function task($id) { return DB::table(Probe::TASK)->where('id', $id)->first(); }
    private function change($id, $action, $extra = [])
    {
        return $this->probe->mutate(array_merge(['id' => $id, 'round_id' => $this->task($id)->current_round_id, 'action' => $action], $extra), 'admin@example.test');
    }
    private function error($status, $callback)
    {
        try { $callback(); $this->fail('Expected HTTP ' . $status); }
        catch (HttpException $e) { $this->assertSame($status, $e->getStatusCode(), $e->getMessage()); }
    }
    public function testRegistrationReservesNodesBeforeAnyTaskAndRejectsSharedHosts()
    {
        $this->assertSame(['ordinary'], array_column((new ServerService())->getAvailableServers($this->users[0]), 'name'));
        $this->assertCount(0, (new ServerService())->getAvailableUsers([1], 'vmess', 1));
        $shared = $this->node('same-host', '203.0.113.1');
        $this->error(422, function () use ($shared) { $this->probe->registerNode('vmess', $shared->id); });
        $parent = $this->node('parent', '192.0.2.10'); $child = $this->node('child', '192.0.2.11'); $child->parent_id = $parent->id; $child->save();
        $this->error(422, function () use ($parent) { $this->probe->registerNode('vmess', $parent->id); });
        $this->error(422, function () use ($child) { $this->probe->registerNode('vmess', $child->id); });
    }
    public function testFixedBalancedGroupsAndConnectionAuthorizationMatchVisibility()
    {
        $id = $this->create(); $round = $this->task($id)->current_round_id;
        $members = DB::table(Probe::MEMBER)->where('round_id', $round)->get();
        $this->assertSame(2, $members->where('branch', 'a')->count()); $this->assertSame(2, $members->where('branch', 'b')->count());
        foreach ($this->users as $user) {
            $branch = $members->firstWhere('user_id', $user->id)->branch; $node = $branch === 'a' ? 1 : 2; $other = $node === 1 ? 2 : 1;
            for ($i = 0; $i < 3; $i++) {
                $servers = (new ServerService())->getAvailableServers($user);
                $this->assertSame(['probe-' . $node, 'ordinary'], array_column($servers, 'name'));
            }
            $this->assertContains($user->id, (new ServerService())->getAvailableUsers([1], 'vmess', $node)->pluck('id')->all());
            $this->assertNotContains($user->id, (new ServerService())->getAvailableUsers([1], 'vmess', $other)->pluck('id')->all());
        }
        $this->assertNotNull(DB::table(Probe::ROUND)->where('id', $round)->value('a_pull_at'));
        $outsider = $this->user(['plan_id' => $this->plan->id, 'group_id' => 1, 'transfer_enable' => 100]);
        $this->assertSame(['ordinary'], array_column((new ServerService())->getAvailableServers($outsider), 'name'));
    }
    public function testOverlapAndStalePreviewRollbackAllTaskAndLeaseWrites()
    {
        $scope = ['scope' => 'all']; $preview = $this->probe->preview($scope);
        $this->users[0]->update(['email' => 'changed@example.test']);
        $this->error(409, function () use ($preview) { $this->create(['expected_hash' => $preview['hash']]); });
        $this->assertSame(0, DB::table(Probe::TASK)->count()); $this->assertSame(0, DB::table(Probe::ACTIVE)->count());
        $this->create();
        $this->error(409, function () { $this->create(['a_node_id' => $this->nodes[2], 'b_node_id' => $this->nodes[3]]); });
        $this->assertSame(1, DB::table(Probe::TASK)->count());
    }
    public function testChangedNodeConfigRollsBackAndExistingPlanAndMoneyArePreserved()
    {
        ServerVmess::find(2)->update(['server_port' => 8443]);
        $this->error(409, function () { $this->create(); });
        $this->assertSame(0, DB::table(Probe::TASK)->count());
        $this->assertSame(4, DB::table(Probe::NODE)->where('state', 'ready')->count());
        foreach ($this->users as $user) { $this->assertSame(1999, (int)$user->fresh()->balance); $this->assertSame($this->plan->id, (int)$user->fresh()->plan_id); }
    }
    public function testTaskMembershipGrantsOnlyItsDedicatedNodeAcrossPackageGroups()
    {
        $otherPlan = Plan::create(['name' => 'other-plan', 'group_id' => 2]);
        foreach ($this->users->slice(2) as $user) $user->update(['plan_id' => $otherPlan->id, 'group_id' => 2]);
        foreach ([1, 2, 3, 4] as $id) ServerVmess::find($id)->update(['group_id' => [99]]);
        $normal = $this->node('ordinary-2', '198.51.100.2'); $normal->update(['group_id' => [2]]);
        $foreign = $this->node('foreign-ordinary', '198.51.100.3'); $foreign->update(['group_id' => [99]]);
        $preview = $this->probe->preview(['scope' => 'all']);
        $id = $this->create(['scope' => 'all', 'expected_count' => $preview['count'], 'expected_hash' => $preview['hash']]);
        $round = $this->task($id)->current_round_id;
        foreach ($this->users as $user) {
            $member = DB::table(Probe::MEMBER)->where('round_id', $round)->where('user_id', $user->id)->first();
            $node = $member->branch === 'a' ? 1 : 2;
            $expected = ['probe-' . $node, $user->group_id === 1 ? 'ordinary' : 'ordinary-2'];
            $this->assertSame($expected, array_column((new ServerService())->getAvailableServers($user), 'name'));
            $this->assertContains($user->id, (new ServerService())->getAvailableUsers([99], 'vmess', $node)->pluck('id')->all());
            $this->assertNotContains($user->id, (new ServerService())->getAvailableUsers([99], 'vmess', $node === 1 ? 2 : 1)->pluck('id')->all());
            $this->assertNotContains($user->id, (new ServerService())->getAvailableUsers([99], 'vmess', $foreign->id)->pluck('id')->all());
            $this->assertSame(1999, (int)$user->fresh()->balance);
            $this->assertSame((int)$user->plan_id, (int)$user->fresh()->plan_id);
            $this->assertSame((int)$user->group_id, (int)$user->fresh()->group_id);
        }
        $outsider = $this->user(['plan_id' => $otherPlan->id, 'group_id' => 99, 'transfer_enable' => 100]);
        $this->assertSame(['foreign-ordinary'], array_column((new ServerService())->getAvailableServers($outsider), 'name'));
        $this->assertNotContains($outsider->id, (new ServerService())->getAvailableUsers([99], 'vmess', 1)->pluck('id')->all());
        $this->assertNotContains($outsider->id, (new ServerService())->getAvailableUsers([99], 'vmess', 2)->pluck('id')->all());
        $this->change($id, 'pause');
        foreach ($this->users as $user) $this->assertCount(1, (new ServerService())->getAvailableServers($user));
        $this->assertCount(0, (new ServerService())->getAvailableUsers([99], 'vmess', 1));
    }
    public function testPauseDisabledQuotaAndChangedEntryFailClosedThenResume()
    {
        $id = $this->create(); $this->change($id, 'pause');
        $this->assertSame(['ordinary'], array_column((new ServerService())->getAvailableServers($this->users[0]), 'name'));
        $this->assertCount(0, (new ServerService())->getAvailableUsers([1], 'vmess', 1));
        $this->change($id, 'resume'); Environment::$app['config']->set('leak_probe.enabled', false);
        $this->assertSame(['ordinary'], array_column((new ServerService())->getAvailableServers($this->users[0]), 'name'));
        $this->assertCount(0, (new ServerService())->getAvailableUsers([1], 'vmess', 1));
        Environment::$app['config']->set('leak_probe.enabled', true);
        $member = DB::table(Probe::MEMBER)->where('round_id', $this->task($id)->current_round_id)->where('branch', 'a')->first();
        $user = \App\Models\User::find($member->user_id); $user->update(['u' => 100000]);
        $this->assertSame(['ordinary'], array_column((new ServerService())->getAvailableServers($user), 'name'));
        $this->assertNotContains($user->id, (new ServerService())->getAvailableUsers([1], 'vmess', 1)->pluck('id')->all());
        ServerVmess::find(1)->update(['host' => '203.0.113.99']);
        $this->assertCount(0, (new ServerService())->getAvailableUsers([1], 'vmess', 1));
    }
    public function testAccountRoleAndPlanChangesRevokeOnlyTheDedicatedAssignment()
    {
        $id = $this->create();
        $member = DB::table(Probe::MEMBER)->where('round_id', $this->task($id)->current_round_id)->where('branch', 'a')->first();
        $user = \App\Models\User::find($member->user_id);
        foreach ([['is_admin' => 1], ['plan_id' => null], ['plan_id' => 999999]] as $change) {
            $user->update(array_merge(['is_admin' => 0, 'plan_id' => $this->plan->id], $change));
            $this->assertSame(['ordinary'], array_column((new ServerService())->getAvailableServers($user), 'name'));
            $this->assertNotContains($user->id, (new ServerService())->getAvailableUsers([1], 'vmess', 1)->pluck('id')->all());
        }
        $user->update(['is_admin' => 0, 'plan_id' => $this->plan->id]);
        $this->assertSame(['probe-1','ordinary'], array_column((new ServerService())->getAvailableServers($user), 'name'));
        $this->assertContains($user->id, (new ServerService())->getAvailableUsers([1], 'vmess', 1)->pluck('id')->all());
    }
    public function testManualResultsAdvanceReleaseOldNodesAndIdentifyWithoutBanning()
    {
        $id = $this->create(); $root = $this->task($id)->current_round_id;
        $rootA = DB::table(Probe::MEMBER)->where('round_id', $root)->where('branch', 'a')->pluck('user_id')->all();
        sort($rootA);
        $this->error(422, function () use ($id) { $this->change($id, 'advance', ['branch' => 'a']); });
        $this->change($id, 'results', ['result_a' => 'abnormal', 'result_b' => 'normal', 'note' => 'manual observation']);
        $this->change($id, 'advance', ['branch' => 'a', 'a_node_id' => $this->nodes[2], 'b_node_id' => $this->nodes[3]]);
        $second = $this->task($id)->current_round_id;
        $this->assertNotSame($root, $second);
        $this->assertSame($rootA, DB::table(Probe::MEMBER)->where('round_id', $second)->orderBy('user_id')->pluck('user_id')->all());
        $this->assertSame(2, DB::table(Probe::NODE)->where('state', 'ready')->count());
        $this->assertCount(0, (new ServerService())->getAvailableUsers([1], 'vmess', 1));
        $this->error(409, function () use ($id, $root) { $this->probe->mutate(['id' => $id, 'round_id' => $root, 'action' => 'finish'], 'admin'); });
        $this->change($id, 'results', ['result_a' => 'abnormal', 'result_b' => 'normal']);
        $this->change($id, 'advance', ['branch' => 'a']);
        $this->assertSame('identified', $this->task($id)->status); $this->assertSame(0, DB::table(Probe::ACTIVE)->count());
        $this->assertSame(0, \App\Models\User::where('banned', 1)->count());
        $this->assertSame(6, DB::table(Probe::MEMBER)->count());
        $this->assertGreaterThan(3, DB::table(Probe::EVENT)->where('task_id', $id)->count());
    }
    public function testSameNodesCanBeReusedAcrossRoundsAndTasksWithCurrentAuthorizationOnly()
    {
        $id = $this->create(); $first = $this->task($id)->current_round_id;
        $oldMember = DB::table(Probe::MEMBER)->where('round_id', $first)->where('branch', 'b')->first();
        $this->change($id, 'results', ['result_a' => 'abnormal', 'result_b' => 'normal']);
        $this->change($id, 'advance', ['branch' => 'a', 'a_node_id' => $this->nodes[0], 'b_node_id' => $this->nodes[1]]);
        $next = $this->task($id)->current_round_id;
        $this->assertNotSame($first, $next);
        $this->assertNotNull(DB::table(Probe::ROUND)->where('id', $first)->value('closed_at'));
        foreach (['a' => 1, 'b' => 2] as $branch => $nodeId) {
            $expected = DB::table(Probe::MEMBER)->where('round_id', $next)->where('branch', $branch)->orderBy('user_id')->pluck('user_id')->all();
            $actual = (new ServerService())->getAvailableUsers([1], 'vmess', $nodeId)->pluck('id')->sort()->values()->all();
            $this->assertSame($expected, $actual);
            $this->assertNotContains($oldMember->user_id, $actual);
        }
        $this->probe->delivered([['round_id' => $first, 'user_id' => $oldMember->user_id, 'branch' => 'b']], time(), 'subscribe');
        $this->assertSame(0, DB::table(Probe::MEMBER)->where('round_id', $next)->whereNotNull('first_seen_at')->count());
        $this->change($id, 'finish');
        $this->assertSame(4, DB::table(Probe::NODE)->where('state', 'ready')->count());
        $secondTask = $this->create();
        $this->assertNotSame($id, $secondTask);
        $this->assertSame(2, DB::table(Probe::NODE)->where('state', 'active')->count());
        $this->assertSame(10, DB::table(Probe::MEMBER)->count());
        $outsiders = [];
        foreach (['a','b'] as $suffix) $outsiders[] = $this->user(['email' => 'separate-' . $suffix . '@example.test', 'plan_id' => $this->plan->id, 'group_id' => 1, 'transfer_enable' => 100]);
        $scope = ['scope' => 'emails', 'emails' => implode(',', array_map(function ($user) { return $user->email; }, $outsiders))];
        $preview = $this->probe->preview($scope);
        $this->error(409, function () use ($scope, $preview) { $this->create($scope + ['expected_count' => $preview['count'], 'expected_hash' => $preview['hash']]); });
        $this->assertSame(2, DB::table(Probe::TASK)->count());
        $this->assertSame(4, DB::table(Probe::ACTIVE)->count());
    }
    public function testInstallationRestoresLegacyRetiredNodesWithoutChangingHistoryOrActiveNodes()
    {
        $id = $this->create(); $round = DB::table(Probe::ROUND)->first(); $this->change($id, 'finish');
        DB::table(Probe::NODE)->whereIn('id', [$this->nodes[0], $this->nodes[1]])->update(['state' => 'retired']);
        (new LeakProbeSchema())->ensure(); (new LeakProbeSchema())->ensure();
        $this->assertSame(4, DB::table(Probe::NODE)->where('state', 'ready')->count());
        $this->assertSame(4, DB::table(Probe::MEMBER)->count());
        $this->assertSame($round->a_snapshot, DB::table(Probe::ROUND)->where('id', $round->id)->value('a_snapshot'));
        $this->create(); (new LeakProbeSchema())->ensure();
        $this->assertSame(2, DB::table(Probe::NODE)->where('state', 'active')->count());
    }
    public function testDeliveryObservationIsIdempotentAndRemainsOnOriginalRoundAfterAdvance()
    {
        $id = $this->create(); $round = $this->task($id)->current_round_id;
        $member = DB::table(Probe::MEMBER)->where('round_id', $round)->first();
        $mark = ['round_id' => $round, 'user_id' => $member->user_id, 'branch' => $member->branch];
        $at = time() - 100;
        $this->change($id, 'results', ['result_a' => 'abnormal', 'result_b' => 'normal']);
        $this->change($id, 'advance', ['branch' => 'a', 'a_node_id' => $this->nodes[2], 'b_node_id' => $this->nodes[3]]);
        $this->probe->delivered([$mark], $at, 'subscribe'); $this->probe->delivered([$mark], $at, 'subscribe');
        $this->probe->delivered([$mark], $at - 10, 'app');
        $saved = DB::table(Probe::MEMBER)->where('id', $member->id)->first();
        $this->assertSame($at - 10, (int)$saved->first_seen_at); $this->assertSame($at, (int)$saved->last_seen_at);
        $this->assertSame(0, DB::table(Probe::MEMBER)->where('round_id', $this->task($id)->current_round_id)->whereNotNull('first_seen_at')->count());
    }
    public function testOnlyRenderedCompatibleNodesAreObservedAndPrivateMarkersStayOutOfPayload()
    {
        $id = $this->create(); $user = $this->users[0];
        $servers = (new ServerService())->getAvailableServers($user);
        $request = Request::create('/subscribe', 'GET', ['user' => $user]); Environment::$app->instance('request', $request);
        $output = (new \App\Protocols\General($user, $servers))->handle();
        $this->assertCount(1, $request->attributes->get('leak_probe_deliveries'));
        $this->assertStringNotContainsString('_leak_probe', base64_decode($output));
        $request->attributes->set('leak_probe_deliveries', []);
        (new \App\Protocols\Shadowsocks($user, $servers))->handle();
        $this->assertSame([], $request->attributes->get('leak_probe_deliveries'));
        $this->assertSame('', Probe::renderedValue($servers[0], ''));
        $this->assertSame([], $request->attributes->get('leak_probe_deliveries'));
    }
    public function testDeliveryMiddlewareSkips304AndQueuesScalarVersionedEvidenceOn200()
    {
        $id = $this->create(); $request = Request::create('/api/v1/user/server/fetch');
        $server = (new ServerService())->getAvailableServers($this->users[0])[0];
        Environment::$app->instance('request', $request);
        $middleware = new \App\Http\Middleware\LeakProbeDelivery();
        $middleware->handle($request, function () use ($server) { Probe::rendered($server); return new Response('', 304); });
        Environment::$queue->assertNotPushed(\App\Jobs\RecordLeakProbeDeliveryJob::class);
        $middleware->handle($request, function () use ($server) { Probe::rendered($server); return new Response('ok'); });
        Environment::$queue->assertPushed(\App\Jobs\RecordLeakProbeDeliveryJob::class, function ($job) {
            $this->assertSame('subscription_audit', $job->queue); $this->assertSame('user_api', $job->source);
            $this->assertIsInt($job->marks[0]['round_id']); return true;
        });
    }
    public function testSchemaRepeatabilityFinishAndFilteredRoster()
    {
        $id = $this->create(); (new LeakProbeSchema())->ensure();
        $this->assertSame(4, DB::table(Probe::MEMBER)->count());
        $preview = $this->probe->preview(['scope' => 'filters', 'filter' => [['key' => 'balance', 'condition' => '>=', 'value' => 1999]]]);
        $this->assertSame(4, $preview['count']);
        $this->change($id, 'finish'); $this->assertSame('completed', $this->task($id)->status);
        $this->assertSame(0, DB::table(Probe::ACTIVE)->count()); $this->assertSame(4, DB::table(Probe::MEMBER)->count());
        $this->assertCount(0, (new ServerService())->getAvailableUsers([1], 'vmess', 2));
    }

    public function testAllEightNodeTypesUseCompositeIdentityAndTheSameAuthorizationPolicy()
    {
        foreach (Probe::TYPES as $i => $type) {
            $pools = $this->nodes;
            $serverIds = [1, 2];
            if ($type !== 'vmess') {
                $pools = [];
                foreach ([1, 2] as $offset) {
                    $nodeId = DB::table('v2_server_' . $type)->insertGetId(['name' => $type . '-' . $offset,
                        'host' => '192.0.2.' . ($i * 10 + $offset), 'port' => '443', 'server_port' => 443, 'network' => 'tcp',
                        'protocol' => 'vmess', 'cipher' => 'aes-128-gcm', 'group_id' => '[]', 'show' => 1, 'rate' => 1, 'sort' => 1]);
                    $pools[] = $this->probe->registerNode($type, $nodeId);
                }
            } else DB::table('v2_server_vmess')->whereIn('id', [1, 2])->update(['group_id' => '[99]']);
            $id = $this->create(['a_node_id' => $pools[0], 'b_node_id' => $pools[1]]);
            $member = DB::table(Probe::MEMBER)->where('round_id', $this->task($id)->current_round_id)->where('user_id', $this->users[0]->id)->first();
            $expected = $member->branch === 'a' ? 1 : 2; $other = $expected === 1 ? 2 : 1;
            $keys = array_map(function ($node) { return $node['type'] . ':' . $node['id']; }, (new ServerService())->getAvailableServers($this->users[0]));
            $this->assertContains($type . ':' . $expected, $keys);
            $this->assertNotContains($type . ':' . $other, $keys);
            $this->assertContains($this->users[0]->id, (new ServerService())->getAvailableUsers($type === 'vmess' ? [99] : [], $type, $expected)->pluck('id')->all());
            $this->assertNotContains($this->users[0]->id, (new ServerService())->getAvailableUsers($type === 'vmess' ? [99] : [], $type, $other)->pluck('id')->all());
            $this->change($id, 'finish');
        }
    }
}
