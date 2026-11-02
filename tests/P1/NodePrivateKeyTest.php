<?php

namespace Tests\P1;

use App\Http\Controllers\V1\Admin\Server\V2nodeController;
use App\Http\Controllers\V1\User\ServerController;
use App\Http\Middleware\User as UserMiddleware;
use App\Models\ServerV2node;
use App\Models\ServerVless;
use App\Models\User;
use App\Services\AuthService;
use App\Services\LeakProbeService;
use App\Services\ServerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Factory;

class NodePrivateKeyTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (LeakProbeService::TYPES as $type) {
            DB::getSchemaBuilder()->dropIfExists('v2_server_' . $type);
            DB::getSchemaBuilder()->create('v2_server_' . $type, function (Blueprint $t) {
                $t->increments('id');
                foreach (['name', 'host', 'port', 'network', 'cipher', 'protocol'] as $field) $t->string($field)->nullable();
                foreach (['group_id', 'route_id', 'tags', 'networkSettings', 'tlsSettings', 'network_settings', 'tls_settings', 'encryption_settings', 'ruleSettings', 'dnsSettings', 'obfs_password'] as $field) $t->text($field)->nullable();
                foreach (['server_port', 'tls', 'show', 'sort', 'parent_id', 'created_at', 'updated_at', 'disable_sni', 'zero_rtt_handshake', 'up_mbps', 'down_mbps'] as $field) $t->integer($field)->nullable();
                $t->decimal('rate', 10, 3)->default(1);
            });
        }
        app()->instance('validator', new Factory(app('translator'), app()));
        Request::macro('validate', function ($rules) { return app('validator')->make($this->all(), $rules)->validate(); });
    }

    private function remoteNode()
    {
        (new V2nodeController())->save(Request::create('/', 'POST', [
            'group_id' => [1], 'name' => 'Remote TLS fixture', 'host' => 'node.example.test',
            'port' => '443', 'server_port' => 443, 'protocol' => 'vless', 'network' => 'tcp',
            'tls' => 1, 'tls_settings' => ['cert_mode' => 'remote', 'server_name' => 'node.example.test'],
            'disable_sni' => 0, 'zero_rtt_handshake' => 0, 'rate' => 1, 'show' => 1, 'sort' => 1,
        ]));
        return ServerV2node::firstOrFail();
    }

    private function subscriber(int $group = 1)
    {
        return $this->user(['group_id' => $group, 'transfer_enable' => 1024, 'expired_at' => time() + 3600])->fresh();
    }

    public function testUserNodeListHidesGeneratedTlsKeyButPreservesCertificateAndPin(): void
    {
        $node = $this->remoteNode();
        $settings = array_merge($node->tls_settings, [
            'dns_env' => 'secret-dns-env', 'private_key' => 'secret-reality-key',
            'ech_key' => 'secret-ech-key', 'public_key' => 'public-reality-key',
        ]);
        $node->tls_settings = $settings; $node->save();
        $this->assertTrue(openssl_pkey_get_private($settings['tls_key']) !== false);
        $this->assertTrue(openssl_x509_check_private_key($settings['tls_cert'], $settings['tls_key']));
        $user = $this->subscriber();
        $jwt = (new AuthService($user))->generateAuthData(Request::create('/'))['auth_data'];
        $request = Request::create('/api/v1/user/server/fetch'); $request->headers->set('Authorization', $jwt);
        $response = (new UserMiddleware())->handle($request, fn($r) => (new ServerController())->fetch($r))->getData(true);
        $this->assertCount(1, $response['data']);
        $public = $response['data'][0]['tls_settings'];
        foreach (['tls_key', 'private_key', 'ech_key', 'dns_env'] as $field) {
            $this->assertArrayNotHasKey($field, $public, "The user response must not contain tls_settings.{$field}");
        }
        $this->assertSame($settings['tls_cert'], $public['tls_cert']);
        $this->assertSame($settings['pinned_peer_cert_sha256'], $public['pinned_peer_cert_sha256']);
        $this->assertSame('public-reality-key', $public['public_key']);
        foreach (['tls_key', 'private_key', 'ech_key', 'dns_env'] as $field) $this->assertSame($settings[$field], $node->fresh()->tls_settings[$field]);
    }

    public function testAuthorizedNodeConfigurationRetainsItsPrivateKey(): void
    {
        $node = $this->remoteNode();
        $original = $node->tls_settings;
        $original['dns_env'] = 'node-dns-credential';
        $original['private_key'] = 'node-reality-credential';
        $original['ech_key'] = 'node-ech-credential';
        $node->tls_settings = $original; $node->save();
        (new ServerService())->getAvailableV2node($this->subscriber());
        config(['v2board.server_token' => 'fixture-node-auth']);
        $request = Request::create('/api/v2/server/config', 'GET', ['token' => 'fixture-node-auth', 'node_id' => $node->id]);
        $controller = new \App\Http\Controllers\V2\Server\ServerController($request);
        $private = $controller->config($request)->getData(true)['tls_settings'];
        $this->assertTrue(hash_equals($original['tls_key'], $private['tls_key']));
        $this->assertTrue(openssl_x509_check_private_key($private['tls_cert'], $private['tls_key']));
        $this->assertSame($original['pinned_peer_cert_sha256'], $private['pinned_peer_cert_sha256']);
        foreach (['dns_env', 'private_key', 'ech_key'] as $field) $this->assertSame($original[$field], $private[$field]);
    }

    public function testVlessClientSettingsAlsoExcludePrivateKeysAndKeepPublicSettings(): void
    {
        ServerVless::create([
            'name' => 'Legacy TLS fixture', 'host' => 'node.example.test', 'port' => '443',
            'group_id' => [1], 'show' => 1, 'sort' => 1, 'tls' => 1,
            'tls_settings' => ['tls_key' => 'fixture-tls-private', 'private_key' => 'fixture-reality-private', 'ech_key' => 'fixture-ech-private', 'dns_env' => 'fixture-dns-private', 'server_name' => 'node.example.test', 'public_key' => 'fixture-public', 'ech_config' => 'fixture-public-ech'],
        ]);
        $user = $this->subscriber();
        $jwt = (new AuthService($user))->generateAuthData(Request::create('/'))['auth_data'];
        $request = Request::create('/api/v1/user/server/fetch'); $request->headers->set('Authorization', $jwt);
        $response = (new UserMiddleware())->handle($request, fn($r) => (new ServerController())->fetch($r))->getData(true);
        $result = array_values(array_filter($response['data'], fn($node) => ($node['type'] ?? null) === 'vless'));
        $this->assertCount(1, $result);
        foreach (['tls_key', 'private_key', 'ech_key', 'dns_env'] as $field) $this->assertArrayNotHasKey($field, $result[0]['tls_settings']);
        $this->assertSame('fixture-public', $result[0]['tls_settings']['public_key']);
        $this->assertSame('fixture-public-ech', $result[0]['tls_settings']['ech_config']);
        $stored = ServerVless::firstOrFail()->tls_settings;
        foreach (['tls_key', 'private_key', 'ech_key', 'dns_env'] as $field) $this->assertArrayHasKey($field, $stored);
    }

    public function testV2nodeChildrenAcrossProtocolsHideTlsSecretsButKeepPublicCertificateData(): void
    {
        $parent = $this->remoteNode();
        $parentTls = array_merge($parent->tls_settings, [
            'dns_env' => 'parent-dns-secret', 'private_key' => 'parent-reality-secret', 'ech_key' => 'parent-ech-secret',
            'public_key' => 'parent-public-key', 'ech_config' => 'parent-public-ech',
        ]);
        $parent->tls_settings = $parentTls; $parent->save();
        foreach (['vless', 'anytls'] as $i => $protocol) {
            $childTls = array_merge($parentTls, ['dns_env' => "child-dns-{$protocol}", 'tls_cert' => "child-cert-{$protocol}", 'pinned_peer_cert_sha256' => "child-pin-{$protocol}"]);
            ServerV2node::create([
                'name' => "Child {$protocol}", 'host' => 'child.example.test', 'port' => '443', 'server_port' => 443,
                'group_id' => [1], 'parent_id' => $parent->id, 'protocol' => $protocol, 'network' => 'tcp', 'tls' => 1,
                'tls_settings' => $childTls, 'show' => 1, 'sort' => $i + 2, 'rate' => 1,
            ]);
        }
        $available = (new ServerService())->getAvailableV2node($this->subscriber());
        $this->assertCount(3, $available);
        foreach ($available as $node) {
            foreach (['tls_key', 'private_key', 'ech_key', 'dns_env'] as $field) $this->assertArrayNotHasKey($field, $node['tls_settings']);
            $this->assertNotEmpty($node['tls_settings']['tls_cert']);
            $this->assertNotEmpty($node['tls_settings']['pinned_peer_cert_sha256']);
            $this->assertNotEmpty($node['tls_settings']['public_key']);
            $this->assertNotEmpty($node['tls_settings']['ech_config']);
        }
        foreach ([$parent, ...ServerV2node::where('parent_id', $parent->id)->get()] as $stored) {
            foreach (['tls_key', 'private_key', 'ech_key', 'dns_env'] as $field) $this->assertArrayHasKey($field, $stored->fresh()->tls_settings);
        }
    }

    public function testNodeGroupRestrictionStillApplies(): void
    {
        $this->remoteNode();
        $this->assertSame([], (new ServerService())->getAvailableV2node($this->subscriber(2)));
    }
}
