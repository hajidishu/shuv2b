<?php

namespace Tests\P1;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\DatabasePresenceVerifier;

class NodeCopyTest extends DatabaseTestCase
{
    private const TYPES = [
        'shadowsocks' => 'Shadowsocks', 'vmess' => 'Vmess', 'trojan' => 'Trojan',
        'tuic' => 'Tuic', 'hysteria' => 'Hysteria', 'vless' => 'Vless',
        'anytls' => 'Anytls', 'naive' => 'Naive', 'mieru' => 'Mieru', 'v2node' => 'V2node',
    ];
    private const CONTROLLERS = ['anytls' => 'AnyTLS'];

    protected function setUp(): void
    {
        parent::setUp();
        app('validator')->setPresenceVerifier(new DatabasePresenceVerifier(app('db')));
        Request::macro('validate', function ($rules, $messages = []) {
            return app('validator')->make($this->all(), $rules, $messages)->validate();
        });
        $schema = DB::getSchemaBuilder();
        foreach (array_keys(self::TYPES) as $type) {
            $table = 'v2_server_' . ($type === 'anytls' ? 'anytls' : $type);
            $schema->dropIfExists($table);
            $schema->create($table, function (Blueprint $t) {
                $t->increments('id');
                foreach (['name','host','port','network','cipher','obfs','protocol','flow','encryption','congestion_control','udp_relay_mode','obfs_password','listen_ip'] as $field) $t->string($field)->nullable();
                foreach (['group_id','route_id','tags','networkSettings','tlsSettings','ruleSettings','dnsSettings','network_settings','tls_settings','encryption_settings','padding_scheme','trusted_x_forwarded_for','obfs_settings'] as $field) $t->text($field)->nullable();
                foreach (['server_port','rate','show','sort','parent_id','tls','allow_insecure','insecure','version','up_mbps','down_mbps','disable_sni','zero_rtt_handshake','created_at','updated_at'] as $field) $t->integer($field)->nullable();
            });
        }
    }

    private function modelClass(string $type): string
    {
        return 'App\\Models\\Server' . self::TYPES[$type];
    }

    private function insertNode(string $type, array $values = [])
    {
        $model = $this->modelClass($type);
        return $model::create(array_merge([
            'name' => 'copy-' . $type, 'host' => $type . '.example.test', 'port' => '443',
            'network' => 'tcp', 'group_id' => [1], 'route_id' => [], 'tags' => ['fixture-tag'],
            'rate' => 2, 'show' => 1, 'sort' => 4, 'parent_id' => null,
        ], $values));
    }

    private function directCopy(string $type, int $id): void
    {
        $controller = 'App\\Http\\Controllers\\V1\\Admin\\Server\\' . (self::CONTROLLERS[$type] ?? self::TYPES[$type]) . 'Controller';
        (new $controller())->copy(Request::create('/copy', 'POST', ['id' => $id]));
    }

    public function testSingleCopySetsSourceParentAndPreservesNodeStateForAllProtocols(): void
    {
        foreach (array_keys(self::TYPES) as $type) {
            $model = $this->modelClass($type);
            $source = $this->insertNode($type);
            $before = $source->fresh()->toArray();
            $this->directCopy($type, $source->id);
            $copy = $model::where('id', '!=', $source->id)->firstOrFail();
            $this->assertSame($source->id, (int)$copy->parent_id, $type . ' copy parent_id');
            $this->assertSame(0, (int)$copy->show, $type . ' copy show');
            $this->assertSame($before['name'], $copy->name, $type . ' copy name');
            $this->assertSame($before['host'], $copy->host, $type . ' copy host');
            $this->assertSame($before['port'], $copy->port, $type . ' copy port');
            $this->assertSame($before['network'], $copy->network, $type . ' copy network');
            $this->assertSame((string)$before['rate'], (string)$copy->rate, $type . ' copy rate');
            $this->assertSame($before['parent_id'], $source->fresh()->parent_id, $type . ' source parent unchanged');
            $this->assertSame((int)$before['show'], (int)$source->fresh()->show, $type . ' source remains shown');
        }
    }

    public function testCopyOfExistingChildPointsToChildSourceRatherThanItsOldParent(): void
    {
        $child = $this->insertNode('vmess', ['parent_id' => 42]);
        $this->directCopy('vmess', $child->id);
        $model = $this->modelClass('vmess');
        $copy = $model::where('id', '!=', $child->id)->firstOrFail();
        $this->assertSame($child->id, (int)$copy->parent_id);
        $this->assertSame(42, (int)$child->fresh()->parent_id);
        $this->assertSame(1, (int)$child->fresh()->show);
    }

    public function testMixedProtocolBatchCopyUsesEachSourceAsParent(): void
    {
        $vmess = $this->insertNode('vmess', ['parent_id' => 41]);
        $naive = $this->insertNode('naive', ['parent_id' => 42]);
        $result = (new \App\Http\Controllers\V1\Admin\Server\BatchController())->execute(
            Request::create('/batch', 'POST', ['action' => 'copy', 'nodes' => [
                ['type' => 'vmess', 'id' => $vmess->id], ['type' => 'naive', 'id' => $naive->id],
            ]])
        );
        $this->assertSame(2, $result->getData(true)['affected']);
        foreach ([['vmess', $vmess], ['naive', $naive]] as [$type, $source]) {
            $model = $this->modelClass($type);
            $copy = $model::where('id', '!=', $source->id)->firstOrFail();
            $this->assertSame($source->id, (int)$copy->parent_id, $type . ' batch parent');
            $this->assertSame(0, (int)$copy->show, $type . ' batch visibility');
            $this->assertSame(1, (int)$source->fresh()->show, $type . ' source visibility');
            $this->assertSame((int)($type === 'vmess' ? 41 : 42), (int)$source->fresh()->parent_id, $type . ' source parent');
        }
    }

    private function legacyPaddingNodes(): array
    {
        return [
            $this->insertNode('anytls', ['server_port' => 443, 'insecure' => 0, 'padding_scheme' => 1]),
            $this->insertNode('v2node', ['server_port' => 443, 'protocol' => 'vmess', 'tls' => 0, 'disable_sni' => 0,
                'zero_rtt_handshake' => 0, 'padding_scheme' => 'legacy-value']),
        ];
    }

    private function paddingBatch(string $action, array $nodes, array $changes = [])
    {
        return (new \App\Http\Controllers\V1\Admin\Server\BatchController())->execute(
            Request::create('/batch', 'POST', ['action' => $action, 'nodes' => [
                ['type' => 'anytls', 'id' => $nodes[0]->id], ['type' => 'v2node', 'id' => $nodes[1]->id],
            ]] + ($changes ? ['changes' => $changes] : []))
        );
    }

    public function testBatchDeleteDoesNotValidateLegacyPadding(): void
    {
        $nodes = $this->legacyPaddingNodes();
        $this->assertSame(2, $this->paddingBatch('delete', $nodes)->getData(true)['affected']);
        $this->assertSame(0, \App\Models\ServerAnytls::count());
        $this->assertSame(0, \App\Models\ServerV2node::count());
    }

    public function testBatchCopyPreservesLegacyPaddingAndParentWithoutValidatingConfiguration(): void
    {
        $nodes = $this->legacyPaddingNodes();
        $this->assertSame(2, $this->paddingBatch('copy', $nodes)->getData(true)['affected']);
        foreach ($nodes as $node) {
            $copy = $node->newQuery()->where('id', '!=', $node->id)->firstOrFail();
            $this->assertSame($node->padding_scheme, $copy->padding_scheme);
            $this->assertSame($node->id, (int)$copy->parent_id);
            $this->assertSame(0, (int)$copy->show);
        }
    }

    public function testBatchUnrelatedUpdateKeepsLegacyPaddingIntact(): void
    {
        $nodes = $this->legacyPaddingNodes();
        $this->assertSame(2, $this->paddingBatch('update', $nodes, ['rate' => 3])->getData(true)['affected']);
        foreach ($nodes as $node) {
            $this->assertSame($node->padding_scheme, $node->fresh()->padding_scheme);
            $this->assertSame(3, (int)$node->fresh()->rate);
        }
    }

    public function testBatchPaddingChangesStillValidateAndRollbackTogether(): void
    {
        $nodes = $this->legacyPaddingNodes();
        foreach (['1', 'not-json'] as $padding) {
            try {
                $this->paddingBatch('update', $nodes, ['rate' => 9, 'padding_scheme' => $padding]);
                $this->fail('Invalid new padding accepted');
            } catch (\Illuminate\Validation\ValidationException $error) {
                $this->assertArrayHasKey('changes.padding_scheme', $error->errors());
            }
            foreach ($nodes as $node) $this->assertSame(2, (int)$node->fresh()->rate);
        }
        $padding = ['stop=8', '0=30-30'];
        $this->paddingBatch('update', $nodes, ['padding_scheme' => json_encode($padding)]);
        foreach ($nodes as $node) $this->assertSame($padding, $node->fresh()->padding_scheme);
    }
}
