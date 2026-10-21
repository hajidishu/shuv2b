<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BatchController extends Controller
{
    private const TYPES = [
        'shadowsocks' => 'Shadowsocks', 'vmess' => 'Vmess', 'trojan' => 'Trojan',
        'tuic' => 'Tuic', 'hysteria' => 'Hysteria', 'vless' => 'Vless',
        'anytls' => 'AnyTLS', 'v2node' => 'V2node',
    ];
    private const FIELDS = [
        'name', 'group_id', 'route_id', 'parent_id', 'host', 'port', 'server_port',
        'tags', 'rate', 'show', 'sort', 'cipher', 'obfs', 'obfs_settings',
        'tls', 'network', 'networkSettings', 'ruleSettings', 'tlsSettings', 'dnsSettings',
        'network_settings', 'allow_insecure', 'server_name', 'insecure', 'version',
        'up_mbps', 'down_mbps', 'obfs_password', 'disable_sni', 'udp_relay_mode',
        'zero_rtt_handshake', 'congestion_control', 'tls_settings', 'flow',
        'encryption', 'encryption_settings', 'padding_scheme', 'listen_ip',
        'protocol', 'trusted_x_forwarded_for',
    ];

    public function execute(Request $request)
    {
        $payload = $request->validate([
            'action' => 'required|in:update,copy,delete',
            'nodes' => 'required|array|min:1|max:200',
            'nodes.*.type' => 'required|in:' . implode(',', array_keys(self::TYPES)),
            'nodes.*.id' => 'required|integer|min:1',
            'changes' => 'required_if:action,update|array|min:1',
            'changes.rate' => 'sometimes|required|numeric|min:0',
            'changes.show' => 'sometimes|required|in:0,1',
            'changes.sort' => 'sometimes|nullable|integer|min:0',
            'changes.group_id' => 'sometimes|required|array|min:1',
            'changes.group_id.*' => 'integer|exists:v2_server_group,id',
            'changes.route_id' => 'sometimes|nullable|array',
            'changes.route_id.*' => 'integer|exists:v2_server_route,id',
            'changes.tags' => 'sometimes|nullable|array',
        ]);
        $nodes = collect($payload['nodes'])->unique(function ($node) {
            return $node['type'] . ':' . $node['id'];
        })->sortBy(function ($node) {
            return $node['type'] . ':' . sprintf('%020d', $node['id']);
        });
        if ($nodes->count() !== count($payload['nodes'])) {
            throw ValidationException::withMessages(['nodes' => '不能重复选择同一节点']);
        }
        $changes = $request->input('changes', []);
        foreach (array_keys($changes) as $field) {
            if (!in_array($field, self::FIELDS, true)) {
                throw ValidationException::withMessages(['changes' => '不支持修改字段：' . $field]);
            }
        }
        DB::transaction(function () use ($nodes, $payload, $changes, $request) {
            foreach ($nodes as $node) {
                $suffix = self::TYPES[$node['type']];
                $modelClass = 'App\\Models\\Server' . ($node['type'] === 'anytls' ? 'Anytls' : $suffix);
                $server = $modelClass::where('id', $node['id'])->lockForUpdate()->first();
                if (!$server) abort(404, '节点不存在：' . $node['type'] . ':' . $node['id']);
                foreach (array_keys($changes) as $field) {
                    if (!Schema::hasColumn($server->getTable(), $field)) {
                        throw ValidationException::withMessages(['changes' => $node['type'] . ' 不支持字段：' . $field]);
                    }
                }
                if (isset($changes['parent_id'])) {
                    if ((int)$changes['parent_id'] === (int)$server->id || !$modelClass::find($changes['parent_id'])) {
                        throw ValidationException::withMessages(['changes.parent_id' => '父节点无效']);
                    }
                }
                $controller = app(__NAMESPACE__ . '\\' . $suffix . 'Controller');
                $action = $payload['action'] === 'update' ? 'save' : ($payload['action'] === 'delete' ? 'drop' : 'copy');
                $method = new \ReflectionMethod($controller, $action);
                $requestClass = (string)$method->getParameters()[0]->getType();
                $inner = Request::createFrom($request, new $requestClass());
                $data = array_merge($server->toArray(), $changes, ['id' => $server->id]);
                if (in_array($node['type'], ['anytls', 'v2node'], true) && isset($data['padding_scheme'])) {
                    $padding = $data['padding_scheme'];
                    if (is_string($padding)) {
                        $padding = json_decode($padding, true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            throw ValidationException::withMessages(['changes.padding_scheme' => '填充方案必须为有效 JSON']);
                        }
                    }
                    if (!is_array($padding)) {
                        throw ValidationException::withMessages(['changes.padding_scheme' => '填充方案必须为 JSON 数组']);
                    }
                    $data['padding_scheme'] = json_encode($padding);
                }
                $inner->replace($data);
                if ($inner instanceof FormRequest) {
                    $inner->setContainer(app());
                    $validator = Validator::make($data, $inner->rules(), $inner->messages());
                    $validator->validate();
                    $inner->setValidator($validator);
                }
                $controller->{$action}($inner);
                // Older protocol save validators omit sort; keep this explicit patch atomic.
                if ($payload['action'] === 'update' && array_key_exists('sort', $changes)) {
                    $server->update(['sort' => $changes['sort']]);
                }
            }
        }, 3);
        return response(['data' => true, 'affected' => $nodes->count()]);
    }
}
