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
        $settings = $node->tls_settings;
        $this->assertTrue(openssl_pkey_get_private($settings['tls_key']) !== false);
        $this->assertTrue(openssl_x509_check_private_key($settings['tls_cert'], $settings['tls_key']));
        $user = $this->subscriber();
        $jwt = (new AuthService($user))->generateAuthData(Request::create('/'))['auth_data'];
        $request = Request::create('/api/v1/user/server/fetch'); $request->headers->set('Authorization', $jwt);
        $response = (new UserMiddleware())->handle($request, fn($r) => (new ServerController())->fetch($r))->getData(true);
        $this->assertCount(1, $response['data']);
        $public = $response['data'][0]['tls_settings'];
        $this->assertFalse(array_key_exists('tls_key', $public), 'The user response must not contain the server TLS private key');
        $this->assertSame($settings['tls_cert'], $public['tls_cert']);
        $this->assertSame($settings['pinned_peer_cert_sha256'], $public['pinned_peer_cert_sha256']);
        $this->assertTrue(hash_equals($settings['tls_key'], $node->fresh()->tls_settings['tls_key']));
    }

    public function testAuthorizedNodeConfigurationRetainsItsPrivateKey(): void
    {
        $node = $this->remoteNode();
        $original = $node->tls_settings;
        (new ServerService())->getAvailableV2node($this->subscriber());
        config(['v2board.server_token' => 'fixture-node-auth']);
        $request = Request::create('/api/v2/server/config', 'GET', ['token' => 'fixture-node-auth', 'node_id' => $node->id]);
        $controller = new \App\Http\Controllers\V2\Server\ServerController($request);
        $private = $controller->config($request)->getData(true)['tls_settings'];
        $this->assertTrue(hash_equals($original['tls_key'], $private['tls_key']));
        $this->assertTrue(openssl_x509_check_private_key($private['tls_cert'], $private['tls_key']));
        $this->assertSame($original['pinned_peer_cert_sha256'], $private['pinned_peer_cert_sha256']);
    }

    public function testVlessClientSettingsAlsoExcludePrivateKeysAndKeepPublicSettings(): void
    {
        ServerVless::create([
            'name' => 'Legacy TLS fixture', 'host' => 'node.example.test', 'port' => '443',
            'group_id' => [1], 'show' => 1, 'sort' => 1, 'tls' => 1,
            'tls_settings' => ['tls_key' => 'fixture-tls-private', 'private_key' => 'fixture-reality-private', 'ech_key' => 'fixture-ech-private', 'server_name' => 'node.example.test', 'public_key' => 'fixture-public', 'ech_config' => 'fixture-public-ech'],
        ]);
        $result = (new ServerService())->getAvailableVless($this->subscriber());
        $this->assertCount(1, $result);
        foreach (['tls_key', 'private_key', 'ech_key'] as $field) $this->assertFalse(array_key_exists($field, $result[0]['tls_settings']));
        $this->assertSame('fixture-public', $result[0]['tls_settings']['public_key']);
        $this->assertSame('fixture-public-ech', $result[0]['tls_settings']['ech_config']);
    }

    public function testNodeGroupRestrictionStillApplies(): void
    {
        $this->remoteNode();
        $this->assertSame([], (new ServerService())->getAvailableV2node($this->subscriber(2)));
    }
}
