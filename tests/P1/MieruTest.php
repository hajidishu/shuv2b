<?php

namespace Tests\P1;

use App\Http\Controllers\V1\Admin\Server\MieruController;
use App\Http\Controllers\V1\Admin\Server\V2nodeController;
use App\Http\Controllers\V1\Server\UniProxyController;
use App\Models\ServerMieru;
use App\Models\ServerV2node;
use App\Services\MieruSettings;
use App\Services\ServerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\ValidationException;

class MieruTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app('validator')->setPresenceVerifier(new DatabasePresenceVerifier(app('db')));
        Request::macro('validate', function ($rules) { return app('validator')->make($this->all(), $rules)->validate(); });
        $schema = DB::getSchemaBuilder();
        foreach (['v2_server_group','v2_server_route','v2_server_v2node'] as $table) $schema->dropIfExists($table);
        foreach (['v2_server_group','v2_server_route'] as $table) $schema->create($table, function (Blueprint $t) { $t->increments('id'); $t->string('name')->nullable(); });
        DB::table('v2_server_group')->insert(['id' => 1, 'name' => 'Mieru fixture']);
        $schema->create('v2_server_v2node', function (Blueprint $t) {
            $t->increments('id');
            foreach (['group_id','route_id','tags','tls_settings','network_settings','trusted_x_forwarded_for','encryption_settings','padding_scheme'] as $f) $t->text($f)->nullable();
            foreach (['name','host','listen_ip','port','protocol','network','flow','encryption','cipher','congestion_control','udp_relay_mode','obfs','obfs_password'] as $f) $t->string($f)->nullable();
            foreach (['server_port','parent_id','tls','show','sort','disable_sni','zero_rtt_handshake','up_mbps','down_mbps','created_at','updated_at'] as $f) $t->integer($f)->nullable();
            $t->string('rate')->default('1');
        });
        (new \App\Services\MieruSchema())->ensure();
    }
    private function payload(array $extra = []): array
    {
        return array_merge(['name' => 'Mieru fixture', 'group_id' => [1], 'route_id' => [], 'host' => 'mieru.example.test',
            'port' => '2012-2022', 'server_port' => '3012-3022', 'rate' => 0, 'show' => 1, 'network' => 'tcp'], $extra);
    }
    private function node(array $extra = []): ServerMieru
    {
        (new MieruController())->save(Request::create('/', 'POST', $this->payload($extra)));
        return ServerMieru::orderByDesc('id')->first();
    }
    private function subscriber()
    {
        return $this->user(['group_id' => 1, 'uuid' => 'b3441d52-832b-4b8d-aed6-a36d8350f110', 'transfer_enable' => 100000000, 'u' => 0, 'd' => 0, 'expired_at' => null]);
    }
    public function testAdditiveSchemaAndCrudKeepExistingNodes(): void
    {
        $node = $this->node(); (new \App\Services\MieruSchema())->ensure();
        $this->assertSame(1, ServerMieru::count()); $this->assertSame('0', (string)$node->rate);
        $this->assertSame(3012, (int)$node->server_port); $this->assertSame('3012-3022', $node->network_settings['server_port_range']);
        $controller = new MieruController();
        $controller->copy(Request::create('/', 'POST', ['id' => $node->id])); $copy = ServerMieru::orderByDesc('id')->first();
        $this->assertSame(0, (int)$copy->show); $this->assertSame($node->network_settings, $copy->network_settings);
        $controller->update(Request::create('/', 'POST', ['id' => $node->id, 'show' => 0]));
        $controller->drop(Request::create('/', 'POST', ['id' => $copy->id]));
        $this->assertSame(1, ServerMieru::count()); $this->assertSame(0, (int)$node->fresh()->show);
    }
    public function testExistingSqlUpgradeParserPreservesMieruRows(): void
    {
        foreach (['install','update'] as $name) {
            $sql = file_get_contents((getenv('P1_SOURCE_ROOT') ?: dirname(__DIR__, 2)) . '/database/' . $name . '.sql');
            $this->assertSame(1, preg_match('/CREATE TABLE `v2_server_mieru`\s*\(.*?ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/s', $sql, $m));
            $node = $this->node(); (new \App\Services\DatabaseUpgrade())->run($m[0], true);
            (new \App\Services\DatabaseUpgrade())->verify($m[0]);
            $this->assertNotNull($node->fresh());
        }
    }
    public function testPortAndProtocolValidationDoesNotAcceptOtherTransports(): void
    {
        foreach ([['port' => '65536'], ['port' => '30-20'], ['server_port' => '0'], ['server_port' => 'x'], ['network' => 'ws'], ['network' => ''],
            ['network_settings' => ['mtu' => 1279]], ['network_settings' => ['mtu' => 1501]], ['network_settings' => ['multiplexing' => 'wrong']],
            ['network_settings' => ['handshake_mode' => '0rtt']], ['network_settings' => ['traffic_pattern' => '@@not-base64']]] as $bad) {
            try { $this->node($bad); $this->fail('Invalid Mieru parameters accepted'); }
            catch (ValidationException $e) { $this->assertNotEmpty($e->errors()); }
        }
        $this->assertSame(0, ServerMieru::count());
    }
    public function testPortsAndRangesHaveUnambiguousRepresentations(): void
    {
        $node = $this->node(['port' => '00443', 'server_port' => 8443]);
        $this->assertSame('443', $node->port); $this->assertSame(8443, (int)$node->server_port);
        $this->assertArrayNotHasKey('server_port_range', $node->network_settings);
        $config = MieruSettings::nodeConfig($node); $this->assertSame([['port' => 8443, 'protocol' => 'TCP']], $config['port_bindings']);
        $proxy = MieruSettings::mihomo(42, 'uuid', $node->toArray());
        $this->assertSame(443, $proxy['port']); $this->assertArrayNotHasKey('port-range', $proxy);
    }
    public function testPublicDtosUsePermissionsAndNeverExposeServerOnlySettings(): void
    {
        $node = $this->node(['network_settings' => ['server_traffic_pattern' => 'CCo=', 'traffic_pattern' => 'CAE=', 'user_hint_is_mandatory' => 1, 'path' => '/old-ws']]);
        $this->node(['show' => 0]); DB::table('v2_server_group')->insert(['id' => 2]); $this->node(['group_id' => [2]]);
        Cache::put('SERVER_MIERU_LAST_CHECK_AT_' . $node->id, 12345); Cache::put('SERVER_NAIVE_LAST_CHECK_AT_' . $node->id, 67890);
        $public = (new ServerService())->getAvailableMieru($this->subscriber()); $this->assertCount(1, $public);
        $this->assertSame(12345, $public[0]['last_check_at']);
        $this->assertSame('CAE=', $public[0]['network_settings']['traffic_pattern']);
        foreach (['server_traffic_pattern','server_port_range','user_hint_is_mandatory','path'] as $key) $this->assertArrayNotHasKey($key, $public[0]['network_settings']);
        $this->assertSame('CCo=', $node->fresh()->network_settings['server_traffic_pattern']);
    }
    public function testNodeConfigurationAndUserCredentialsMatchSubscriptions(): void
    {
        $node = $this->node(['network' => 'udp']); $user = $this->subscriber(); config(['v2board.server_token' => 'fixture-mieru-token']);
        $request = Request::create('/', 'GET', ['token' => 'fixture-mieru-token', 'node_type' => 'mieru', 'node_id' => $node->id]);
        $api = new UniProxyController($request); $config = $api->config($request)->getData(true);
        $this->assertSame([['portRange' => '3012-3022', 'protocol' => 'UDP']], $config['port_bindings']);
        $this->assertSame(1400, $config['mtu']); $this->assertArrayNotHasKey('tls_settings', $config);
        $users = $api->user($request)->getData(true)['users']; $this->assertCount(1, $users);
        $this->assertSame((string)$user->id, $users[0]['username']); $this->assertSame($user->uuid, $users[0]['password']); $this->assertArrayNotHasKey('email', $users[0]);
        $this->assertNotNull(Cache::get('SERVER_MIERU_LAST_CHECK_AT_' . $node->id));
        $bad = Request::create('/', 'GET', ['token' => 'wrong', 'node_type' => 'mieru', 'node_id' => $node->id]);
        try { new UniProxyController($bad); $this->fail('Wrong node token accepted'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(500, $e->getStatusCode()); }
    }
    public function testV2nodeMieruDoesNotCarryTlsOrOtherProtocolSettings(): void
    {
        $params = $this->payload(['protocol' => 'mieru', 'tls' => 2, 'disable_sni' => 0, 'zero_rtt_handshake' => 0,
            'tls_settings' => ['private_key' => 'fixture-private-key'], 'flow' => 'xtls-rprx-vision', 'padding_scheme' => '["old-anytls"]',
            'network_settings' => ['path' => '/old-ws', 'mtu' => 1400, 'server_traffic_pattern' => 'CCo=']]);
        (new V2nodeController())->save(Request::create('/', 'POST', $params)); $node = ServerV2node::firstOrFail();
        $this->assertSame(0, (int)$node->tls); $this->assertNull($node->tls_settings); $this->assertNull($node->flow); $this->assertNull($node->padding_scheme);
        $this->assertArrayNotHasKey('path', $node->network_settings);
        $user = $this->subscriber(); $public = (new ServerService())->getAvailableV2node($user); $this->assertArrayNotHasKey('server_traffic_pattern', $public[0]['network_settings']);
        config(['v2board.server_token' => 'fixture-v2-mieru']); $r = Request::create('/', 'GET', ['token' => 'fixture-v2-mieru', 'node_id' => $node->id]);
        $config = (new \App\Http\Controllers\V2\Server\ServerController($r))->config($r)->getData(true);
        $this->assertSame('mieru', $config['protocol']); $this->assertSame('CCo=', $config['traffic_pattern']); $this->assertSame(3012, (int)$config['server_port']);
        $r = Request::create('/', 'GET', ['token' => 'fixture-v2-mieru', 'node_type' => 'v2node', 'node_id' => $node->id]);
        $users = (new UniProxyController($r))->user($r)->getData(true)['users']; $this->assertSame((string)$user->id, $users[0]['username']); $this->assertSame($user->uuid, $users[0]['password']);
    }
    public function testOfficialSimpleLinksEncodeNamesIpv6CredentialsAndRanges(): void
    {
        $node = $this->node(['host' => '2001:db8::1', 'name' => '测试 & Mieru', 'network' => 'udp'])->toArray(); $node['type'] = 'mieru';
        $uri = \App\Utils\Helper::buildUri('uuid-password', $node, 42); $parts = parse_url(trim($uri)); parse_str($parts['query'], $query);
        $this->assertSame('mierus', $parts['scheme']); $this->assertSame('[2001:db8::1]', $parts['host']); $this->assertSame('42', $parts['user']);
        $this->assertSame('uuid-password', $parts['pass']); $this->assertSame('测试 & Mieru', $query['profile']); $this->assertSame('2012-2022', $query['port']);
        $this->assertSame('UDP', $query['protocol']); $this->assertSame('MULTIPLEXING_LOW', $query['multiplexing']); $this->assertArrayNotHasKey('port', $parts);
    }
    public function testMihomoUsesOnlySupportedFieldsAndExcludesUnrepresentableUdpMtu(): void
    {
        $node = $this->node()->toArray(); $node['type'] = 'mieru'; $proxy = MieruSettings::mihomo(42, 'uuid', $node);
        $this->assertSame('2012-2022', $proxy['port-range']); $this->assertArrayNotHasKey('port', $proxy); $this->assertSame('42', $proxy['username']);
        $this->assertSame('TCP', $proxy['transport']); $this->assertArrayNotHasKey('tls', $proxy); $this->assertArrayNotHasKey('server_traffic_pattern', $proxy);
        $node['network'] = 'udp'; $node['network_settings']['mtu'] = 1280; $this->assertNull(MieruSettings::mihomo(42, 'uuid', $node));
        $this->assertStringContainsString('mtu=1280', MieruSettings::uri(42, 'uuid', $node));
    }
    public function testBatchEditingAndOrderingPreserveBackendRanges(): void
    {
        $node = $this->node(); $other = $this->node(['name' => 'Other']);
        (new \App\Http\Controllers\V1\Admin\Server\BatchController())->execute(Request::create('/', 'POST', ['action' => 'update', 'nodes' => [['type' => 'mieru', 'id' => $node->id]], 'changes' => ['rate' => 2, 'sort' => 3]]));
        $this->assertSame('2', (string)$node->fresh()->rate); $this->assertSame('3012-3022', $node->fresh()->network_settings['server_port_range']); $this->assertSame('0', (string)$other->fresh()->rate);
        (new \App\Http\Controllers\V1\Admin\Server\ManageController())->sort(Request::create('/', 'POST', ['mieru' => [$node->id => 7]]));
        $this->assertSame(7, (int)$node->fresh()->sort);
    }
    public function testTrafficReportingRetainsExistingAccountingQueueContract(): void
    {
        $node = $this->node(); $user = $this->subscriber(); config(['v2board.server_token' => 'fixture-mieru-traffic']);
        $r = Request::create('/', 'POST', ['token' => 'fixture-mieru-traffic', 'node_type' => 'mieru', 'node_id' => $node->id]);
        $r->setJson(new \Symfony\Component\HttpFoundation\ParameterBag([$user->id => [1024, 2048]])); (new UniProxyController($r))->push($r);
        Environment::$queue->assertPushed(\App\Jobs\TrafficFetchJob::class); Environment::$queue->assertPushed(\App\Jobs\StatServerJob::class);
        $this->assertSame(1, Cache::get('SERVER_MIERU_ONLINE_USER_' . $node->id));
    }
    public function testChangingARangeBackToItsFirstSinglePortDoesNotRestoreOldRange(): void
    {
        $node = $this->node(); $settings = $node->network_settings; unset($settings['server_port_range']);
        (new MieruController())->save(Request::create('/', 'POST', $this->payload(['id' => $node->id, 'server_port' => '3012', 'network_settings' => $settings])));
        $this->assertSame(3012, (int)$node->fresh()->server_port);
        $this->assertArrayNotHasKey('server_port_range', $node->fresh()->network_settings);
    }
    public function testValidUnicodeTagsAreNotTruncatedByJsonStorage(): void
    {
        $tag = str_repeat('测', 120); $node = $this->node(['tags' => [$tag, 'Mieru']]);
        $this->assertSame([$tag, 'Mieru'], $node->fresh()->tags);
    }
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRealMihomoUserAgentAndExplicitMetaFlagProduceMieruYaml(): void
    {
        foreach (\App\Services\LeakProbeService::TYPES as $type) {
            if (in_array($type, ['mieru', 'v2node'], true)) continue;
            DB::getSchemaBuilder()->dropIfExists('v2_server_' . $type);
            DB::getSchemaBuilder()->create('v2_server_' . $type, function (Blueprint $t) { $t->increments('id'); $t->integer('sort')->nullable(); });
        }
        \Illuminate\Support\Facades\File::swap(new \Illuminate\Filesystem\Filesystem());
        if (!class_exists('File')) class_alias(\Illuminate\Support\Facades\File::class, 'File');
        $this->node(); $user = $this->subscriber();
        foreach ([[null, 'mihomo/1.19.0'], ['meta', 'fixture-converter'], ['mihomo', 'fixture-converter']] as [$flag, $agent]) {
            $r = Request::create('/subscribe', 'GET', $flag === null ? [] : ['flag' => $flag], [], [], ['HTTP_USER_AGENT' => $agent]);
            $r->user = $user;
            $body = (new \App\Http\Controllers\V1\Client\ClientController())->subscribe($r);
            $yaml = \Symfony\Component\Yaml\Yaml::parse($body);
            $nodes = array_values(array_filter($yaml['proxies'], fn($p) => ($p['type'] ?? null) === 'mieru'));
            $this->assertSame('ClashMeta', $r->attributes->get('subscription_audit_format'));
            $this->assertCount(1, $nodes); $this->assertSame((string)$user->id, $nodes[0]['username']);
            $this->assertSame($user->uuid, $nodes[0]['password']); $this->assertSame('2012-2022', $nodes[0]['port-range']);
        }
        $r = Request::create('/subscribe', 'GET', ['flag' => 'general'], [], [], ['HTTP_USER_AGENT' => 'mihomo/1.19.0']); $r->user = $user;
        $general = (new \App\Http\Controllers\V1\Client\ClientController())->subscribe($r);
        $this->assertSame('General', $r->attributes->get('subscription_audit_format'));
        $this->assertStringContainsString('mierus://', base64_decode($general));
    }
}
