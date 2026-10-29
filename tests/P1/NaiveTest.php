<?php

namespace Tests\P1;

use App\Http\Controllers\V1\Admin\Server\NaiveController;
use App\Http\Controllers\V1\Admin\Server\V2nodeController;
use App\Http\Controllers\V1\Server\UniProxyController;
use App\Models\ServerNaive;
use App\Models\ServerV2node;
use App\Services\NaiveSettings;
use App\Services\ServerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\ValidationException;

class NaiveTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app('validator')->setPresenceVerifier(new DatabasePresenceVerifier(app('db')));
        Request::macro('validate', function ($rules) { return app('validator')->make($this->all(), $rules)->validate(); });
        $schema = DB::getSchemaBuilder();
        foreach (['v2_server_group','v2_server_route','v2_server_v2node'] as $table) $schema->dropIfExists($table);
        foreach (['v2_server_group','v2_server_route'] as $table) $schema->create($table, function (Blueprint $t) { $t->increments('id'); $t->string('name')->nullable(); });
        DB::table('v2_server_group')->insert(['id' => 1, 'name' => 'Naive fixture']);
        $schema->create('v2_server_v2node', function (Blueprint $t) {
            $t->increments('id');
            foreach (['group_id','route_id','tags','tls_settings','network_settings','trusted_x_forwarded_for','encryption_settings','padding_scheme'] as $f) $t->text($f)->nullable();
            foreach (['name','host','listen_ip','port','protocol','network','flow','encryption','cipher','congestion_control','udp_relay_mode','obfs','obfs_password'] as $f) $t->string($f)->nullable();
            foreach (['server_port','parent_id','tls','show','sort','disable_sni','zero_rtt_handshake','up_mbps','down_mbps','created_at','updated_at'] as $f) $t->integer($f)->nullable();
            $t->string('rate')->default('1');
        });
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['name' => 'Naive fixture', 'group_id' => [1], 'route_id' => [],
            'host' => 'naive.example.test', 'port' => '443', 'server_port' => 443, 'rate' => 0,
            'show' => 1, 'network' => 'tcp', 'tls' => 1, 'tls_settings' => ['cert_mode' => 'http'],
            'congestion_control' => 'bbr'], $extra);
    }

    private function createNaive(array $extra = []): ServerNaive
    {
        (new NaiveController())->save(Request::create('/', 'POST', $this->payload($extra)));
        return ServerNaive::orderBy('id', 'desc')->first();
    }

    private function subscriber()
    {
        return $this->user(['group_id' => 1, 'uuid' => 'b3441d52-832b-4b8d-aed6-a36d8350f110', 'banned' => 0,
            'transfer_enable' => 100000000, 'u' => 0, 'd' => 0, 'expired_at' => null]);
    }

    public function testSchemaAndCrudAreRepeatableWithoutLosingExistingData(): void
    {
        $node = $this->createNaive();
        (new \App\Services\NaiveSchema())->ensure(); (new \App\Services\NaiveSchema())->ensure();
        $this->assertSame(1, ServerNaive::count());
        $this->assertSame('0', (string)$node->rate);
        $this->assertSame('naive.example.test', $node->tls_settings['server_name']);
        $controller = new NaiveController();
        $controller->save(Request::create('/', 'POST', $this->payload(['id' => $node->id, 'name' => 'Edited'])));
        $controller->copy(Request::create('/', 'POST', ['id' => $node->id]));
        $copy = ServerNaive::orderByDesc('id')->first();
        $this->assertSame('Edited', $copy->name); $this->assertSame(0, (int)$copy->show);
        $this->assertSame(1, (int)$node->fresh()->show);
        $controller->drop(Request::create('/', 'POST', ['id' => $copy->id]));
        $controller->update(Request::create('/', 'POST', ['id' => $node->id, 'show' => 0]));
        $this->assertSame(1, ServerNaive::count()); $this->assertSame(0, (int)$node->fresh()->show);
    }

    public function testInstallAndUpdateSqlUseTheExistingNonDestructiveUpgradeParser(): void
    {
        $root = getenv('P1_SOURCE_ROOT') ?: dirname(__DIR__, 2);
        foreach (['install','update'] as $name) {
            $sql = file_get_contents($root . '/database/' . $name . '.sql');
            $this->assertSame(1, preg_match('/CREATE TABLE `v2_server_naive`\s*\(.*?ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/s', $sql, $match));
            $node = $this->createNaive();
            (new \App\Services\DatabaseUpgrade())->run($match[0], true);
            (new \App\Services\DatabaseUpgrade())->verify($match[0]);
            $this->assertNotNull(ServerNaive::find($node->id));
        }
    }

    public function testInvalidTransportsSecurityAndCertificatesCannotBeSaved(): void
    {
        foreach ([['network' => 'ws'], ['tls' => 0], ['tls' => 2], ['congestion_control' => 'bbr2'],
            ['port' => 70000], ['port' => '443-445'], ['group_id' => [9999]],
            ['tls_settings' => ['cert_mode' => 'self']], ['tls_settings' => ['cert_mode' => 'remote']],
            ['tls_settings' => ['cert_mode' => 'file']], ['tls_settings' => ['allow_insecure' => 1]]] as $extra) {
            try { $this->createNaive($extra); $this->fail('Invalid Naive settings were saved'); }
            catch (ValidationException $error) { $this->assertNotEmpty($error->errors()); }
        }
        $this->assertSame(0, ServerNaive::count());
    }

    public function testPublicVisibilityNeverIncludesServerCertificateOrDnsSecrets(): void
    {
        $node = $this->createNaive(['tls_settings' => ['cert_mode' => 'dns', 'provider' => 'fixture-provider', 'dns_env' => 'fixture-dns-secret', 'key_file' => '/fixture/private.key']]);
        $this->createNaive(['show' => 0]);
        DB::table('v2_server_group')->insert(['id' => 2, 'name' => 'Other']);
        $this->createNaive(['group_id' => [2]]);
        $servers = (new ServerService())->getAvailableNaive($this->subscriber());
        $this->assertCount(1, $servers); $this->assertSame($node->id, $servers[0]['id']);
        $this->assertSame(['server_name' => 'naive.example.test'], $servers[0]['tls_settings']);
        $this->assertStringNotContainsString('fixture-dns-secret', json_encode($servers));
        $this->assertSame('fixture-dns-secret', $node->fresh()->tls_settings['dns_env']);
    }

    public function testNodeApiUsesTheSameCredentialsAsSubscriptionsAndKeepsAuthentication(): void
    {
        $node = $this->createNaive(['network' => 'udp', 'congestion_control' => 'reno']);
        $user = $this->subscriber(); config(['v2board.server_token' => 'fixture-naive-token']);
        $request = Request::create('/', 'GET', ['token' => 'fixture-naive-token', 'node_type' => 'naive', 'node_id' => $node->id]);
        $controller = new UniProxyController($request);
        $config = $controller->config($request)->getData(true);
        $this->assertSame('udp', $config['network']); $this->assertSame('reno', $config['quic_congestion_control']);
        $this->assertSame(1, $config['tls']);
        $users = $controller->user($request)->getData(true)['users'];
        $this->assertCount(1, $users); $this->assertSame((string)$user->id, $users[0]['username']);
        $this->assertSame($user->uuid, $users[0]['password']); $this->assertArrayNotHasKey('email', $users[0]);
        $this->assertNotNull(Cache::get('SERVER_NAIVE_LAST_CHECK_AT_' . $node->id));
        $bad = Request::create('/', 'GET', ['token' => 'wrong', 'node_type' => 'naive', 'node_id' => $node->id]);
        try { new UniProxyController($bad); $this->fail('Wrong node token accepted'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(500, $error->getStatusCode()); }
    }

    public function testV2nodeNaiveSettingsAreIsolatedAndAvailableThroughExistingV2Api(): void
    {
        $params = $this->payload(['protocol' => 'naive', 'disable_sni' => 0, 'zero_rtt_handshake' => 0,
            'listen_ip' => '127.0.0.1', 'network' => 'udp', 'congestion_control' => 'cubic',
            'network_settings' => ['path' => '/old-websocket'], 'flow' => 'xtls-rprx-vision',
            'padding_scheme' => '["old-anytls"]', 'tls_settings' => ['cert_mode' => 'dns','dns_env' => 'fixture-v2-secret']]);
        (new V2nodeController())->save(Request::create('/', 'POST', $params));
        $node = ServerV2node::first();
        $this->assertNull($node->network_settings); $this->assertNull($node->padding_scheme); $this->assertNull($node->flow);
        $public = (new ServerService())->getAvailableV2node($this->subscriber());
        $this->assertSame(['server_name' => 'naive.example.test'], $public[0]['tls_settings']);
        config(['v2board.server_token' => 'fixture-node-token']);
        $request = Request::create('/', 'GET', ['token' => 'fixture-node-token','node_id' => $node->id]);
        $data = (new \App\Http\Controllers\V2\Server\ServerController($request))->config($request)->getData(true);
        $this->assertSame('naive', $data['protocol']); $this->assertSame('cubic', $data['quic_congestion_control']);
        $this->assertSame('fixture-v2-secret', $data['tls_settings']['dns_env']);
    }

    public function testSubscriptionsUseDocumentedNaiveFieldsAndVersionGate(): void
    {
        $node = $this->createNaive(['host' => '2001:db8::1','network' => 'udp'])->toArray();
        $node['type'] = 'naive';$user = $this->subscriber()->toArray();
        $config = NaiveSettings::outbound($user['id'], $user['uuid'], $node);
        $this->assertSame((string)$user['id'], $config['username']); $this->assertTrue($config['quic']);
        $this->assertSame(['enabled','server_name'], array_keys($config['tls']));
        $uri = \App\Utils\Helper::buildUri($user['uuid'], $node, $user['id']);
        $this->assertStringStartsWith('naive+quic://' . $user['id'] . ':', $uri);
        $this->assertStringContainsString('@[2001:db8::1]:443', $uri);
        $this->assertSame($uri, base64_decode((new \App\Protocols\General($user, [$node]))->handle()));
        $this->assertSame($uri, base64_decode((new \App\Protocols\V2rayN($user, [$node]))->handle()));
        $method = new \ReflectionMethod(\App\Protocols\Singbox\Singbox::class, 'buildProxies');$method->setAccessible(true);
        $this->assertCount(1, $method->invoke(new \App\Protocols\Singbox\Singbox($user, [$node], ['version'=>'1.13.0'])));
        $this->assertSame([], $method->invoke(new \App\Protocols\Singbox\Singbox($user, [$node], ['version'=>'1.12.9'])));
        $v2 = array_merge($node,['type'=>'v2node','protocol'=>'naive']);
        $this->assertSame($uri, \App\Utils\Helper::buildUri($user['uuid'], $v2, $user['id']));
    }

    private function subscriptionRequest($flag, string $userAgent, $user): array
    {
        $schema = DB::getSchemaBuilder();
        foreach (\App\Services\LeakProbeService::TYPES as $type) {
            if (!$schema->hasTable('v2_server_' . $type)) $schema->create('v2_server_' . $type, function (Blueprint $t) {
                $t->increments('id'); $t->integer('sort')->nullable();
            });
        }
        $request = Request::create('/subscribe', 'GET', $flag === null ? [] : ['flag' => $flag], [], [], ['HTTP_USER_AGENT' => $userAgent]);
        $request->user = $user;
        $previous = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $_SERVER['HTTP_USER_AGENT'] = $userAgent;
        try { $response = (new \App\Http\Controllers\V1\Client\ClientController())->subscribe($request); }
        finally {
            if ($previous === null) unset($_SERVER['HTTP_USER_AGENT']);
            else $_SERVER['HTTP_USER_AGENT'] = $previous;
        }
        if (is_string($response)) return [$request, $response];
        $body = json_decode($response->getContent(), true);
        // The lightweight response factory wraps pre-serialized strings as JSON.
        if (is_string($body) && strpos($body, '{') === 0) $body = json_decode($body, true);
        return [$request, $body];
    }

    public function testExplicitSingboxFormatStillReadsTheClientVersionFromUserAgent(): void
    {
        $this->createNaive(); $user = $this->subscriber();
        foreach ([['sing-box','sing-box 1.13.0',1],['sing-box','sing-box 1.12.9',0],[null,'sing-box 1.13.0',1],['sing-box 1.13.0','converter',1]] as [$flag,$agent,$count]) {
            [$request,$body] = $this->subscriptionRequest($flag,$agent,$user);
            $this->assertSame('Singbox',$request->attributes->get('subscription_audit_format'));
            $this->assertCount($count,array_filter($body['outbounds'],fn($outbound)=>$outbound['type']==='naive'));
        }
    }

    public function testSingboxSubscriptionDoesNotDuplicateStatusDisplayNodes(): void
    {
        $this->createNaive(); config(['v2board.show_info_to_server_enable'=>1]);
        [, $body] = $this->subscriptionRequest('sing-box 1.13.0','sing-box 1.13.0',$this->subscriber());
        $this->assertCount(1,array_filter($body['outbounds'],fn($outbound)=>$outbound['type']==='naive'));
    }

    public function testExplicitUriFormatKeepsPriorityOverSingboxUserAgent(): void
    {
        $this->createNaive();
        [$request,$body] = $this->subscriptionRequest('v2rayn','sing-box 1.13.0',$this->subscriber());
        $this->assertSame('V2rayN',$request->attributes->get('subscription_audit_format'));
        $this->assertStringContainsString('naive+https://',base64_decode($body));
    }

    public function testBatchUpdateAndSortingIncludeNaiveWithoutChangingOtherNodes(): void
    {
        $node = $this->createNaive();$other=$this->createNaive(['name'=>'Other']);
        $request=Request::create('/','POST',['action'=>'update','nodes'=>[['type'=>'naive','id'=>$node->id]],'changes'=>['rate'=>2,'sort'=>3]]);
        (new \App\Http\Controllers\V1\Admin\Server\BatchController())->execute($request);
        $this->assertSame('2',(string)$node->fresh()->rate);$this->assertSame(3,(int)$node->fresh()->sort);
        $this->assertSame('0',(string)$other->fresh()->rate);
        (new \App\Http\Controllers\V1\Admin\Server\ManageController())->sort(Request::create('/','POST',['naive'=>[$node->id=>7]]));
        $this->assertSame(7,(int)$node->fresh()->sort);
    }

    public function testTrafficReportingUsesTheExistingAccountingJobs(): void
    {
        $node=$this->createNaive();$user=$this->subscriber();config(['v2board.server_token'=>'fixture-traffic-token']);
        $request=Request::create('/','POST',['token'=>'fixture-traffic-token','node_type'=>'naive','node_id'=>$node->id]);
        $request->setJson(new \Symfony\Component\HttpFoundation\ParameterBag([$user->id=>[1024,2048]]));
        (new UniProxyController($request))->push($request);
        Environment::$queue->assertPushed(\App\Jobs\TrafficFetchJob::class);
        Environment::$queue->assertPushed(\App\Jobs\StatServerJob::class);
        $this->assertSame(1,Cache::get('SERVER_NAIVE_ONLINE_USER_'.$node->id));
    }
}
