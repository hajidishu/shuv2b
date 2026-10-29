<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class NaiveSettings
{
    // The panel uses the same TLS certificate keys as its other node APIs.
    // Naive has no Reality, uTLS fingerprint or WebSocket transport.
    public const TLS_KEYS = ['server_name','cert_mode','cert_file','key_file','provider','dns_env','reject_unknown_sni'];

    public static function rules(): array
    {
        return [
            'listen_ip' => 'nullable|ip',
            'host' => 'required|string|max:255',
            'port' => 'required|integer|between:1,65535',
            'server_port' => 'required|integer|between:1,65535',
            'rate' => 'required|numeric|min:0',
            'network' => 'nullable|in:tcp,udp',
            'tls' => 'required|in:1',
            'tls_settings' => 'nullable|array',
            'tls_settings.server_name' => 'nullable|string|max:255',
            'tls_settings.cert_mode' => 'nullable|in:http,dns,file',
            'tls_settings.cert_file' => 'required_if:tls_settings.cert_mode,file|nullable|string|max:2048',
            'tls_settings.key_file' => 'required_if:tls_settings.cert_mode,file|nullable|string|max:2048',
            'tls_settings.provider' => 'nullable|string|max:255',
            'tls_settings.dns_env' => 'nullable|string|max:8192',
            'tls_settings.reject_unknown_sni' => 'nullable|in:0,1',
            'congestion_control' => 'nullable|in:bbr,cubic,reno',
        ];
    }

    public static function normalize(array $params): array
    {
        Validator::make($params, self::rules())->validate();
        $tls = $params['tls_settings'] ?? [];
        if (!empty($tls['allow_insecure'])) throw ValidationException::withMessages(['tls_settings.allow_insecure' => 'Naive 不支持跳过证书验证']);
        $tls = array_intersect_key($tls, array_flip(self::TLS_KEYS));
        $tls['cert_mode'] = $tls['cert_mode'] ?? 'http';
        if (empty($tls['server_name']) && !empty($params['host'])) $tls['server_name'] = $params['host'];
        $params['tls_settings'] = $tls;
        $params['tls'] = 1;
        $params['listen_ip'] = $params['listen_ip'] ?? '0.0.0.0';
        $params['network'] = $params['network'] ?? '';
        $params['congestion_control'] = $params['congestion_control'] ?? 'bbr';
        if (($params['protocol'] ?? null) === 'naive') {
            // Switching a V2node protocol must not retain another protocol's settings.
            $params['network_settings'] = null; $params['trusted_x_forwarded_for'] = null;
            $params['flow'] = null; $params['encryption'] = null; $params['encryption_settings'] = null;
            $params['cipher'] = null; $params['padding_scheme'] = null;
            $params['obfs'] = null; $params['obfs_password'] = null;
            $params['disable_sni'] = 0; $params['zero_rtt_handshake'] = 0;
            $params['udp_relay_mode'] = null; $params['up_mbps'] = 0; $params['down_mbps'] = 0;
        }
        return $params;
    }

    public static function publicNode(array $node): array
    {
        // Certificate paths, private material and DNS provider credentials stay on the server API.
        $tls = $node['tls_settings'] ?? [];
        $node['tls_settings'] = array_intersect_key($tls, ['server_name' => true]);
        return $node;
    }

    public static function outbound($userId, string $uuid, array $node): array
    {
        return [
            'type' => 'naive', 'tag' => $node['name'], 'server' => $node['host'],
            'server_port' => (int)$node['port'], 'username' => (string)$userId, 'password' => $uuid,
            'quic' => ($node['network'] ?? 'tcp') === 'udp',
            'tls' => ['enabled' => true, 'server_name' => $node['tls_settings']['server_name'] ?? $node['host']],
        ];
    }

    public static function uri($userId, string $uuid, array $node): string
    {
        if (!$userId) return '';
        $scheme = ($node['network'] ?? 'tcp') === 'udp' ? 'naive+quic' : 'naive+https';
        $auth = rawurlencode((string)$userId) . ':' . rawurlencode($uuid);
        $host = \App\Utils\Helper::formatHost($node['host']);
        $sni = $node['tls_settings']['server_name'] ?? '';
        $query = $sni !== '' ? '?' . http_build_query(['sni' => $sni], '', '&', PHP_QUERY_RFC3986) : '';
        return $scheme . '://' . $auth . '@' . $host . ':' . $node['port'] . $query . '#' . rawurlencode($node['name']) . "\r\n";
    }
}
