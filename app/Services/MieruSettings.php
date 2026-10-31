<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;

class MieruSettings
{
    public const CLIENT_KEYS = ['mtu', 'multiplexing', 'handshake_mode', 'traffic_pattern'];
    public const SETTINGS_KEYS = ['mtu', 'multiplexing', 'handshake_mode', 'traffic_pattern', 'server_traffic_pattern', 'user_hint_is_mandatory', 'server_port_range'];

    public static function portRange($value): ?array
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/\A([0-9]{1,5})(?:-([0-9]{1,5}))?\z/', (string)$value, $m)) return null;
        $first = (int)$m[1]; $last = isset($m[2]) ? (int)$m[2] : $first;
        return $first >= 1 && $last <= 65535 && $first <= $last ? [$first, $last] : null;
    }

    public static function rules(): array
    {
        $ports = function ($attribute, $value, $fail) { if (!self::portRange($value)) $fail('端口必须为 1–65535 的整数或递增范围，例如 2012-2022'); };
        $pattern = function ($attribute, $value, $fail) {
            if ($value !== null && $value !== '' && (!is_string($value) || !preg_match('/\A[A-Za-z0-9+\/]*={0,2}\z/', $value) || base64_decode($value, true) === false)) $fail('请填写 mieru export traffic-pattern 导出的 Base64 内容');
        };
        return [
            'group_id' => 'required|array|min:1', 'group_id.*' => 'integer|exists:v2_server_group,id',
            'route_id' => 'nullable|array', 'route_id.*' => 'integer|exists:v2_server_route,id',
            'listen_ip' => 'nullable|ip', 'host' => 'required|string|max:255',
            'port' => ['required', $ports], 'server_port' => ['required', $ports],
            'rate' => 'required|numeric|min:0', 'network' => 'required|in:tcp,udp',
            'network_settings' => 'nullable|array',
            'network_settings.mtu' => 'nullable|integer|between:1280,1500',
            'network_settings.multiplexing' => 'nullable|in:MULTIPLEXING_OFF,MULTIPLEXING_LOW,MULTIPLEXING_MIDDLE,MULTIPLEXING_HIGH',
            'network_settings.handshake_mode' => 'nullable|in:HANDSHAKE_STANDARD,HANDSHAKE_NO_WAIT',
            'network_settings.traffic_pattern' => ['nullable', 'string', 'max:8192', $pattern],
            'network_settings.server_traffic_pattern' => ['nullable', 'string', 'max:8192', $pattern],
            'network_settings.user_hint_is_mandatory' => 'nullable|in:0,1',
            'network_settings.server_port_range' => ['nullable', $ports],
        ];
    }

    public static function normalize(array $params): array
    {
        Validator::make($params, self::rules())->validate();
        $settings = array_intersect_key($params['network_settings'] ?? [], array_flip(self::SETTINGS_KEYS));
        // V2node stores an integer primary port. The full range stays in network_settings.
        $range = self::portRange($params['server_port']);
        $stored = isset($settings['server_port_range']) ? self::portRange($settings['server_port_range']) : null;
        if ($range[0] === $range[1] && $stored && $stored[0] === $range[0]) $range = $stored;
        $params['server_port'] = $range[0];
        unset($settings['server_port_range']);
        if ($range[0] !== $range[1]) $settings['server_port_range'] = $range[0] . '-' . $range[1];
        $client = self::portRange($params['port']);
        $params['port'] = $client[0] === $client[1] ? (string)$client[0] : $client[0] . '-' . $client[1];
        $settings['mtu'] = (int)($settings['mtu'] ?? 1400);
        $settings['multiplexing'] = $settings['multiplexing'] ?? 'MULTIPLEXING_LOW';
        $settings['handshake_mode'] = $settings['handshake_mode'] ?? 'HANDSHAKE_STANDARD';
        $settings['user_hint_is_mandatory'] = (int)($settings['user_hint_is_mandatory'] ?? 0);
        foreach (['traffic_pattern', 'server_traffic_pattern'] as $key) if (empty($settings[$key])) unset($settings[$key]);
        $params['network_settings'] = $settings;
        $params['listen_ip'] = $params['listen_ip'] ?? '0.0.0.0';
        if (($params['protocol'] ?? null) === 'mieru') {
            $params['tls'] = 0; $params['tls_settings'] = null; $params['trusted_x_forwarded_for'] = null;
            $params['flow'] = null; $params['encryption'] = null; $params['encryption_settings'] = null;
            $params['cipher'] = null; $params['padding_scheme'] = null; $params['congestion_control'] = null;
            $params['obfs'] = null; $params['obfs_password'] = null;
            $params['disable_sni'] = 0; $params['zero_rtt_handshake'] = 0;
            $params['udp_relay_mode'] = null; $params['up_mbps'] = 0; $params['down_mbps'] = 0;
        }
        return $params;
    }

    public static function publicNode(array $node): array
    {
        $node['network_settings'] = array_intersect_key($node['network_settings'] ?? [], array_flip(self::CLIENT_KEYS));
        return $node;
    }

    public static function nodeConfig($node): array
    {
        $s = $node->network_settings ?? [];
        $binding = isset($s['server_port_range']) ? ['portRange' => $s['server_port_range']] : ['port' => (int)$node->server_port];
        $binding['protocol'] = strtoupper($node->network);
        return ['listen_ip' => $node->listen_ip, 'server_port' => (int)$node->server_port, 'network' => $node->network,
            'network_settings' => $s, 'port_bindings' => [$binding], 'mtu' => (int)($s['mtu'] ?? 1400),
            'traffic_pattern' => $s['server_traffic_pattern'] ?? '', 'user_hint_is_mandatory' => (bool)($s['user_hint_is_mandatory'] ?? false)];
    }

    public static function mihomo($userId, string $uuid, array $node): ?array
    {
        $s = $node['network_settings'] ?? [];
        // Mihomo has no MTU option. Do not emit a UDP profile that cannot match its server.
        if (!$userId || (($node['network'] ?? 'tcp') === 'udp' && (int)($s['mtu'] ?? 1400) !== 1400)) return null;
        $proxy = ['name' => $node['name'], 'type' => 'mieru', 'server' => trim($node['host'], '[]'),
            'transport' => strtoupper($node['network'] ?? 'tcp'), 'udp' => true, 'username' => (string)$userId, 'password' => $uuid,
            'multiplexing' => $s['multiplexing'] ?? 'MULTIPLEXING_LOW', 'handshake-mode' => $s['handshake_mode'] ?? 'HANDSHAKE_STANDARD'];
        $port = $node['mport'] ?? $node['port'];
        if (strpos((string)$port, '-') !== false) $proxy['port-range'] = (string)$port; else $proxy['port'] = (int)$port;
        if (!empty($s['traffic_pattern'])) $proxy['traffic-pattern'] = $s['traffic_pattern'];
        return $proxy;
    }

    public static function uri($userId, string $uuid, array $node): string
    {
        if (!$userId) return '';
        $s = $node['network_settings'] ?? [];
        $query = ['profile' => $node['name'], 'mtu' => (int)($s['mtu'] ?? 1400),
            'multiplexing' => $s['multiplexing'] ?? 'MULTIPLEXING_LOW', 'handshake-mode' => $s['handshake_mode'] ?? 'HANDSHAKE_STANDARD',
            'port' => (string)($node['mport'] ?? $node['port']), 'protocol' => strtoupper($node['network'] ?? 'tcp')];
        if (!empty($s['traffic_pattern'])) $query['traffic-pattern'] = $s['traffic_pattern'];
        return 'mierus://' . rawurlencode((string)$userId) . ':' . rawurlencode($uuid) . '@' . \App\Utils\Helper::formatHost($node['host'])
            . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) . "\r\n";
    }
}
