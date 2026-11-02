<?php

namespace Tests\P1;

use App\Http\Controllers\V1\Server\UniProxyController;
use App\Models\ServerV2node;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UniProxyAliveTest extends DatabaseTestCase
{
    private $originalPost;
    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPost = $_POST;
        $_POST = [];
        config(['v2board.server_token' => 'fixture-node-auth']);
        DB::getSchemaBuilder()->dropIfExists('v2_server_v2node');
        DB::getSchemaBuilder()->create('v2_server_v2node', function ($table) {
            $table->increments('id'); $table->text('group_id');
            $table->integer('created_at'); $table->integer('updated_at');
        });
        ServerV2node::create(['group_id' => [1]]);
    }
    protected function tearDown(): void
    {
        $_POST = $this->originalPost;
        parent::tearDown();
    }
    private function post(array $body): array
    {
        $request = Request::create('/?token=fixture-node-auth&node_type=v2node&node_id=1', 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], json_encode($body));
        return (new UniProxyController($request))->alive($request)->getData(true);
    }
    private function realRedis(): void
    {
        $socket = getenv('P1_TEST_REDIS_SOCKET');
        if (!$socket) $this->markTestSkipped('Set P1_TEST_REDIS_SOCKET to a disposable Redis Unix socket.');
        $manager = new RedisManager(app(), 'phpredis', ['default' => ['host' => $socket, 'port' => 0, 'database' => 0]]);
        Cache::swap(new Repository(new RedisStore($manager, 'alive-test-' . bin2hex(random_bytes(8)) . ':')));
    }
    public function testEmptyAndMetadataOnlyReportsNeverIssueABatchRead(): void
    {
        $store = \Mockery::mock(\Illuminate\Contracts\Cache\Store::class);
        $store->shouldNotReceive('many');
        Cache::swap(new Repository($store));
        foreach ([[], ['node_id' => '1', 'node_type' => 'v2node', 'token' => 'fixture-node-auth'], [23 => false], [23 => 'invalid-list']] as $body) {
            $this->assertSame(['data' => true], $this->post($body));
        }
    }
    public function testRealPhpRedisHandlesEmptyReportsAndNormalOnlineUpdates(): void
    {
        $this->realRedis();
        foreach ([[], ['node_id' => '1', 'node_type' => 'v2node', 'token' => 'fixture-node-auth'], [23 => false]] as $body) {
            $this->assertSame(['data' => true], $this->post($body));
        }
        $this->assertSame(['data' => true], $this->post([23 => ['192.0.2.1_1', '2001:db8::1_1']]));
        $this->assertSame(2, Cache::get('ALIVE_IP_USER_23')['alive_ip']);
        $this->assertSame(['192.0.2.1_1', '2001:db8::1_1'], Cache::get('ALIVE_IP_USER_23')['v2node1']['aliveips']);
        $this->assertSame(['data' => true], $this->post([23 => []]));
        $this->assertSame(0, Cache::get('ALIVE_IP_USER_23')['alive_ip']);
    }
    public function testCountingModesPreserveOtherFreshNodesAndRemoveExpiredReports(): void
    {
        foreach ([0 => 3, 1 => 2] as $mode => $expected) {
            config(['v2board.device_limit_mode' => $mode]);
            Cache::put('ALIVE_IP_USER_23', [
                'v2node2' => ['aliveips' => ['192.0.2.1_2'], 'lastupdateAt' => time()],
                'v2node3' => ['aliveips' => ['192.0.2.99_3'], 'lastupdateAt' => time() - 101],
                'alive_ip' => 2,
            ], 120);
            $this->post([23 => ['192.0.2.1_1', '2001:db8::1_1']]);
            $stored = Cache::get('ALIVE_IP_USER_23');
            $this->assertSame($expected, $stored['alive_ip']);
            $this->assertArrayHasKey('v2node2', $stored);
            $this->assertArrayNotHasKey('v2node3', $stored);
        }
    }
}
