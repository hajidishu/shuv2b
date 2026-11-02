<?php

namespace Tests\P1;

use App\Http\Controllers\V1\Admin\Server\HysteriaController;
use App\Http\Controllers\V1\Admin\Server\V2nodeController;
use App\Http\Controllers\V1\Client\ClientController;
use App\Protocols\Singbox\HysteriaPorts;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Services\LeakProbeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SingboxHysteriaPortsTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Request::macro('validate', function ($rules) { return app('validator')->make($this->all(), $rules)->validate(); });
        foreach (LeakProbeService::TYPES as $type) {
            DB::getSchemaBuilder()->dropIfExists('v2_server_' . $type);
            DB::getSchemaBuilder()->create('v2_server_' . $type, function (Blueprint $table) {
                $table->increments('id');
                foreach (['name', 'host', 'port', 'protocol', 'network', 'server_name', 'obfs', 'obfs_password'] as $key) $table->string($key, $key === 'port' ? 11 : 255)->nullable();
                foreach (['group_id', 'route_id', 'tags', 'tls_settings', 'network_settings', 'encryption_settings'] as $key) $table->text($key)->nullable();
                foreach (['server_port', 'sort', 'show', 'parent_id', 'version', 'insecure', 'up_mbps', 'down_mbps', 'tls', 'disable_sni', 'zero_rtt_handshake', 'created_at', 'updated_at'] as $key) $table->integer($key)->nullable();
                $table->integer('rate')->default(1);
            });
        }
    }

    private function nodes(string $ports): void
    {
        $common = ['group_id' => [1], 'host' => 'hy2.example.test', 'port' => $ports, 'server_port' => 443,
            'rate' => 1, 'show' => 1, 'obfs' => 'salamander', 'obfs_password' => 'fixture-obfs'];
        (new HysteriaController())->save(Request::create('/', 'POST', $common + [
            'name' => 'Standalone HY2', 'version' => 2, 'insecure' => 0, 'server_name' => 'hy2.example.test', 'sort' => 1,
        ]));
        (new V2nodeController())->save(Request::create('/', 'POST', $common + [
            'name' => 'V2node HY2', 'protocol' => 'hysteria2', 'network' => 'tcp', 'tls' => 1,
            'tls_settings' => ['server_name' => 'hy2.example.test', 'cert_mode' => 'none', 'allow_insecure' => 0],
            'disable_sni' => 0, 'zero_rtt_handshake' => 0, 'sort' => 2,
        ]));
    }

    private function subscribe($user, ?string $flag, string $agent): array
    {
        $request = Request::create('/api/v1/client/subscribe', 'GET', $flag === null ? [] : ['flag' => $flag], [], [], ['HTTP_USER_AGENT' => $agent]);
        $request->user = $user;
        $response = (new ClientController())->subscribe($request);
        $body = json_decode($response->getContent(), true);
        // The lightweight response factory serializes an already encoded JSON body again.
        if (is_string($body)) $body = json_decode($body, true);
        return [$request, $body];
    }

    public function ports(): array
    {
        return [
            ['443', ['server_port' => 443]],
            ['20000-30000', ['server_ports' => ['20000:30000']]],
            ['443,8443', ['server_ports' => ['443:443', '8443:8443']]],
            ['443,800-900', ['server_ports' => ['443:443', '800:900']]],
            ['1-3,5-7', ['server_ports' => ['1:3', '5:7']]],
            ['443,20000-30000,8443', ['server_ports' => ['443:443', '20000:30000', '8443:8443']]],
            [' 443, 20000 - 30000, 40000-41000 ', ['server_ports' => ['443:443', '20000:30000', '40000:41000']]],
            ['1-65535', ['server_ports' => ['1:65535']]],
        ];
    }

    /** @dataProvider ports */
    public function testPortListsPreserveAllEntriesAndSinglePortFallback($ports, $expected): void
    {
        $this->assertSame($expected, HysteriaPorts::build($ports));
        $this->assertSame(['server_port' => (int)explode('-', explode(',', trim($ports))[0])[0]], HysteriaPorts::build($ports, false));
    }

    public function clients(): array
    {
        return [
            [null, 'sing-box 1.10.7', 'SingboxOld', false],
            ['sing-box', 'sing-box 1.11.0', 'SingboxOld', true],
            [null, 'sing-box 1.11.15', 'SingboxOld', true],
            ['sing-box 1.11.0', 'fixture-converter', 'SingboxOld', true],
            ['sing-box', 'sing-box 1.12.0', 'Singbox', true],
            [null, 'sing-box 1.13.0', 'Singbox', true],
            ['sing-box', 'fixture-converter', 'SingboxOld', false],
        ];
    }

    /** @dataProvider clients */
    public function testActualSubscribeRouteUsesClientVersionForBothNodeTypes($flag, $agent, $format, $hopping): void
    {
        $this->nodes('20000-30000');
        $user = $this->user(['group_id' => 1, 'transfer_enable' => 1024, 'uuid' => 'fixture-uuid']);
        [$request, $body] = $this->subscribe($user, $flag, $agent);
        $this->assertSame($format, $request->attributes->get('subscription_audit_format'));
        $nodes = array_values(array_filter($body['outbounds'], fn($node) => $node['type'] === 'hysteria2'));
        $this->assertCount(2, $nodes);
        foreach ($nodes as $node) {
            if ($hopping) {
                $this->assertSame(['20000:30000'], $node['server_ports']);
                $this->assertArrayNotHasKey('server_port', $node);
            } else {
                $this->assertSame(20000, $node['server_port']);
                $this->assertArrayNotHasKey('server_ports', $node);
            }
            $this->assertSame('fixture-uuid', $node['password']);
            $this->assertSame(['type' => 'salamander', 'password' => 'fixture-obfs'], $node['obfs']);
            $this->assertSame('hy2.example.test', $node['tls']['server_name']);
            $this->assertFalse($node['tls']['insecure']);
            $this->assertArrayNotHasKey('hop_interval', $node); // Keep the client's standard default interval.
        }
    }

    public function storedPorts(): array
    {
        // Existing panel tables have VARCHAR(11) ports; no schema change is needed.
        return array_values(array_filter($this->ports(), fn($case) => strlen($case[0]) <= 11));
    }

    /** @dataProvider storedPorts */
    public function testStoredNodesReachBothSubscriptionTemplatesWithoutTruncation($ports, $expected): void
    {
        $this->nodes($ports);
        $user = $this->user(['group_id' => 1, 'transfer_enable' => 1024, 'uuid' => 'fixture-uuid']);
        foreach (['1.11.0', '1.12.0'] as $version) {
            [, $body] = $this->subscribe($user, 'sing-box', 'sing-box ' . $version);
            $nodes = array_values(array_filter($body['outbounds'], fn($node) => $node['type'] === 'hysteria2'));
            $this->assertCount(2, $nodes);
            foreach ($nodes as $node) {
                $actual = array_intersect_key($node, array_flip(['server_port', 'server_ports']));
                $this->assertSame($expected, $actual);
            }
        }
    }

    public function testLegacyHysteriaOneStillFallsBackWhileModernTemplateKeepsHopping(): void
    {
        $this->nodes('20000-30000');
        DB::table('v2_server_hysteria')->update(['version' => 1, 'obfs' => null]);
        $user = $this->user(['group_id' => 1, 'transfer_enable' => 1024, 'uuid' => 'fixture-uuid']);
        foreach (['1.11.0' => false, '1.12.0' => true] as $version => $hopping) {
            [, $body] = $this->subscribe($user, 'sing-box', 'sing-box ' . $version);
            $node = array_values(array_filter($body['outbounds'], fn($node) => $node['type'] === 'hysteria'))[0];
            $this->assertSame('fixture-uuid', $node['auth_str']);
            $this->assertSame($hopping, isset($node['server_ports']));
            $this->assertSame(!$hopping, isset($node['server_port']));
        }
    }
}
