<?php

namespace App\Services;

use App\Models\ServerExternal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ExternalNodeSettings
{
    public const PROTOCOLS = ['vless', 'vmess', 'trojan', 'shadowsocks', 'hysteria', 'tuic', 'anytls', 'naive', 'mieru'];
    private const CIPHERS = ['aes-128-gcm', 'aes-192-gcm', 'aes-256-gcm', 'chacha20-ietf-poly1305', '2022-blake3-aes-128-gcm', '2022-blake3-aes-256-gcm'];

    private static function invalid(string $message): void
    {
        throw ValidationException::withMessages(['config' => [$message]]);
    }

    public static function normalize(array $input): array
    {
        $data = Validator::make($input, [
            'protocol' => 'required|in:' . implode(',', self::PROTOCOLS),
            'host' => ['required', 'string', 'max:255', 'regex:/\A[^\s\/\\\\@?#\[\]]+\z/u'],
            'port' => 'required|integer|between:1,65535',
            'auth' => 'required|array', 'auth.uuid' => 'nullable|uuid',
            'auth.password' => 'nullable|string|max:4096', 'auth.username' => 'nullable|string|max:255',
            'network' => 'nullable|in:tcp,udp,ws,grpc,http,httpupgrade,xhttp',
            'network_settings' => 'nullable|array', 'tls' => 'nullable|integer|in:0,1,2',
            'tls_settings' => 'nullable|array', 'flow' => 'nullable|in:xtls-rprx-vision',
            'cipher' => 'nullable|in:' . implode(',', self::CIPHERS),
            'obfs' => 'nullable|in:http,salamander', 'obfs_password' => 'nullable|string|max:4096',
            'congestion_control' => 'nullable|in:cubic,new_reno,bbr',
            'udp_relay_mode' => 'nullable|in:native,quic', 'disable_sni' => 'nullable|boolean',
            'zero_rtt_handshake' => 'nullable|boolean',
        ])->validate();
        $protocol = $data['protocol']; $auth = $data['auth'];
        foreach (['uuid', 'password', 'username'] as $key) {
            if (isset($auth[$key]) && preg_match('/[\x00-\x1f\x7f]/', $auth[$key])) self::invalid('连接凭据不能包含换行或控制字符');
        }
        $keys = in_array($protocol, ['vless', 'vmess'], true) ? ['uuid'] :
            ($protocol === 'tuic' ? ['uuid', 'password'] : (in_array($protocol, ['naive', 'mieru'], true) ? ['username', 'password'] : ['password']));
        foreach ($keys as $key) if (!isset($auth[$key]) || $auth[$key] === '') self::invalid('请填写节点的 ' . $key);
        $data['auth'] = array_intersect_key($auth, array_flip($keys));
        $data['port'] = (int)$data['port'];
        $data['network'] = $data['network'] ?? ($protocol === 'hysteria' || $protocol === 'tuic' ? 'udp' : 'tcp');
        $networks = in_array($protocol, ['vless','vmess'], true) ? ['tcp','ws','grpc','http','httpupgrade','xhttp'] :
            ($protocol === 'trojan' ? ['tcp','ws','grpc'] : (in_array($protocol,['naive','mieru'],true) ? ['tcp','udp'] :
                (in_array($protocol,['hysteria','tuic'],true) ? ['udp'] : ['tcp'])));
        if (!in_array($data['network'], $networks, true)) self::invalid('该协议不支持所选传输方式');
        $data['tls'] = (int)($data['tls'] ?? (in_array($protocol, ['shadowsocks','mieru','vless','vmess'], true) ? 0 : 1));
        if (!in_array($protocol, ['vless','vmess','shadowsocks','mieru'], true) && $data['tls'] !== 1) self::invalid('该协议需要 TLS');
        if ($data['tls'] === 2 && $protocol !== 'vless') self::invalid('仅 VLESS 支持此处的 Reality 配置');
        if (in_array($protocol,['shadowsocks','mieru'],true) && $data['tls'] !== 0) self::invalid('该协议不支持此处的 TLS 配置');
        $tls = $data['tls_settings'] ?? [];
        $tls = Validator::make($tls, [
            'server_name' => ['nullable','string','max:255','regex:/\A[^\s,;\x00-\x1f]*\z/u'],
            'allow_insecure' => 'nullable|boolean', 'fingerprint' => 'nullable|string|max:64',
            'public_key' => 'nullable|string|max:255', 'short_id' => 'nullable|string|max:16',
        ])->validate();
        if ($data['tls'] === 2 && empty($tls['public_key'])) self::invalid('Reality 节点需要公钥');
        if ($protocol === 'naive' && !empty($tls['allow_insecure'])) self::invalid('Naive 节点需要有效的 TLS 证书');
        $data['tls_settings'] = $tls;
        $network = $data['network_settings'] ?? [];
        if ($data['network'] === 'http' && array_key_exists('Host', $network)) {
            $network['host'] = $network['host'] ?? $network['Host'];
            unset($network['Host']);
        }
        $allowed = $protocol === 'mieru' ? MieruSettings::CLIENT_KEYS : ['path','headers','serviceName','host','mode','extra'];
        if (array_diff(array_keys($network), $allowed)) self::invalid('包含不支持的传输配置字段');
        if ($data['network'] === 'ws') {
            $network = Validator::make($network, ['path'=>'nullable|string|max:2048', 'headers'=>'nullable|array', 'headers.Host'=>'nullable|string|max:255'])->validate();
        } elseif ($data['network'] === 'grpc') {
            $network = Validator::make($network, ['serviceName'=>'nullable|string|max:2048'])->validate();
        } elseif (in_array($data['network'], ['http','httpupgrade','xhttp'], true)) {
            $network = Validator::make($network, ['path'=>'nullable|string|max:2048','host'=>'nullable|string|max:255','mode'=>'nullable|in:auto,packet-up,stream-up,stream-one','extra'=>'nullable|array'])->validate();
        } elseif ($protocol === 'shadowsocks' && ($data['obfs'] ?? '') === 'http') {
            $network = Validator::make($network, ['host'=>'nullable|string|max:255','path'=>'nullable|string|max:2048'])->validate();
        } elseif ($protocol === 'mieru') {
            $network = Validator::make($network, [
                'mtu'=>'nullable|integer|between:1280,1500','multiplexing'=>'nullable|in:MULTIPLEXING_OFF,MULTIPLEXING_LOW,MULTIPLEXING_MIDDLE,MULTIPLEXING_HIGH',
                'handshake_mode'=>'nullable|in:HANDSHAKE_STANDARD,HANDSHAKE_NO_WAIT','traffic_pattern'=>'nullable|string|max:8192',
            ])->validate();
        } else $network = [];
        $data['network_settings'] = $network;
        if ($protocol === 'shadowsocks' && empty($data['cipher'])) self::invalid('请选择 Shadowsocks 加密方式');
        if ($protocol === 'hysteria' && !empty($data['obfs']) && ($data['obfs'] !== 'salamander' || empty($data['obfs_password']))) self::invalid('Hysteria2 混淆需要 salamander 及混淆密码');
        if (!empty($data['flow']) && ($protocol !== 'vless' || $data['network'] !== 'tcp' || !$data['tls'])) self::invalid('Vision 需要 VLESS TCP + TLS/Reality');
        return $data;
    }

    private static function decode64(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $result = base64_decode($value . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if ($result === false) self::invalid('链接中的 Base64 数据无效');
        return $result;
    }

    // SIP002 plugin separators can be escaped inside option values.
    private static function pluginParts(string $value): array
    {
        $parts = []; $part = ''; $escaped = false;
        foreach (str_split($value) as $character) {
            if ($escaped) { $part .= $character; $escaped = false; }
            elseif ($character === '\\') $escaped = true;
            elseif ($character === ';') { $parts[] = $part; $part = ''; }
            else $part .= $character;
        }
        if ($escaped) self::invalid('插件参数末尾的转义字符不完整');
        $parts[] = $part;
        return $parts;
    }

    public static function pluginValue(string $value): string
    {
        return strtr($value, ['\\'=>'\\\\', ':'=>'\\:', ';'=>'\\;', '='=>'\\=']);
    }

    // Local parsing only: importing a share link never fetches its host or a remote subscription.
    // URI references: XTLS/Xray-core discussions/716, Shadowsocks SIP002 and Hysteria 2 URI-Scheme.
    public static function parse(string $uri): array
    {
        $uri = trim($uri);
        if (preg_match('/[\x00-\x20\x7f]/', $uri)) self::invalid('请填写一条完整、正确转义的节点链接');
        if (strpos($uri, 'vmess://') === 0) {
            $json = json_decode(self::decode64(substr($uri, 8)), true);
            if (!is_array($json)) self::invalid('VMess 链接需要 Base64 JSON 格式');
            if ($unknown = array_diff(array_keys($json), ['v','ps','add','port','id','aid','scy','net','type','host','path','tls','sni','allowInsecure','fp','mode','extra'])) self::invalid('暂不支持 VMess 参数：' . implode('、', $unknown));
            if (!in_array($json['aid'] ?? 0, [0,'0',''], true) || !in_array($json['scy'] ?? 'auto', ['auto',''], true) || !in_array($json['type'] ?? 'none', ['none',''], true)) self::invalid('仅支持 VMess AEAD、auto 加密及无 TCP 伪装的链接');
            if (!in_array($json['tls'] ?? '', ['','tls'], true)) self::invalid('不支持此 VMess TLS 安全类型');
            $network = $json['net'] ?? 'tcp';
            $settings = $network === 'ws' ? ['path'=>$json['path'] ?? '/', 'headers'=>['Host'=>$json['host'] ?? '']] :
                ($network === 'grpc' ? ['serviceName'=>$json['path'] ?? ''] : ['path'=>$json['path'] ?? '/', 'host'=>$json['host'] ?? '']);
            if ($network === 'xhttp') {
                $settings['mode'] = $json['mode'] ?? 'auto';
                if (isset($json['extra'])) {
                    $settings['extra'] = is_string($json['extra']) ? json_decode($json['extra'], true) : $json['extra'];
                    if (!is_array($settings['extra'])) self::invalid('XHTTP extra 必须是 JSON 对象');
                }
            }
            $config = ['protocol'=>'vmess','host'=>trim($json['add'] ?? '', '[]'),'port'=>$json['port'] ?? null,'auth'=>['uuid'=>$json['id'] ?? ''],
                'network'=>$network, 'network_settings'=>$settings, 'tls'=>empty($json['tls']) ? 0 : 1,
                'tls_settings'=>['server_name'=>$json['sni'] ?? '', 'allow_insecure'=>self::boolValue($json['allowInsecure'] ?? 0), 'fingerprint'=>$json['fp'] ?? 'chrome']];
            return ['name'=>$json['ps'] ?? $config['host'], 'config'=>self::normalize($config)];
        }
        if (strpos($uri, 'ss://') === 0 && strpos(explode('#', substr($uri, 5), 2)[0], '@') === false) {
            $parts = explode('#', substr($uri, 5), 2);
            $uri = 'ss://' . self::decode64($parts[0]) . (isset($parts[1]) ? '#' . $parts[1] : '');
        }
        $parts = parse_url($uri);
        if (!$parts || empty($parts['host']) || empty($parts['scheme'])) self::invalid('节点链接格式无效');
        $scheme = strtolower($parts['scheme']);
        $protocol = ['ss'=>'shadowsocks','hy2'=>'hysteria','hysteria2'=>'hysteria','naive+https'=>'naive','naive+quic'=>'naive','mierus'=>'mieru'][$scheme] ?? $scheme;
        if (!in_array($protocol, self::PROTOCOLS, true) || $scheme === 'hysteria') self::invalid('不支持此节点链接协议');
        parse_str($parts['query'] ?? '', $q);
        foreach ($q as $value) if (!is_scalar($value)) self::invalid('节点链接参数必须为单个值');
        $tlsKeys = ['sni','peer','insecure','allowInsecure','allow_insecure'];
        $allowed = [
            'vless'=>array_merge($tlsKeys,['security','fp','type','host','path','serviceName','encryption','flow','pbk','sid','headerType','quicSecurity','mode','extra']),
            'vmess'=>array_merge($tlsKeys,['security','fp','type','host','path','serviceName']),
            'trojan'=>array_merge($tlsKeys,['type','host','path','serviceName','security']),
            'shadowsocks'=>['plugin'], 'hysteria'=>array_merge($tlsKeys,['obfs','obfs-password']),
            'tuic'=>array_merge($tlsKeys,['alpn','congestion_control','udp_relay_mode','disable_sni','zero_rtt_handshake']),
            'anytls'=>array_merge($tlsKeys,['fp','type']), 'naive'=>['sni'],
            'mieru'=>['mtu','multiplexing','handshake-mode','traffic-pattern','port','protocol','profile'],
        ][$protocol];
        if ($unknown = array_diff(array_keys($q), $allowed)) self::invalid('暂不支持链接参数：' . implode('、', $unknown));
        if (!empty($q['encryption']) && $q['encryption'] !== 'none') self::invalid('暂不支持此 VLESS 加密方式');
        if (!empty($q['alpn']) && $q['alpn'] !== 'h3') self::invalid('暂不支持自定义 ALPN');
        if (!empty($q['headerType']) && $q['headerType'] !== 'none') self::invalid('暂不支持 TCP 伪装参数');
        $user = rawurldecode($parts['user'] ?? ''); $password = rawurldecode($parts['pass'] ?? '');
        $auth = in_array($protocol,['vless','vmess'],true) ? ['uuid'=>$user] :
            ($protocol === 'tuic' ? ['uuid'=>$user,'password'=>$password] :
                (in_array($protocol,['naive','mieru'],true) ? ['username'=>$user,'password'=>$password] : ['password'=>$user . (isset($parts['pass']) ? ':' . $password : '')]));
        $network = $q['type'] ?? (in_array($protocol,['hysteria','tuic'],true) || $scheme === 'naive+quic' ? 'udp' : 'tcp');
        if ($network === 'raw') $network = 'tcp';
        if ($protocol === 'mieru') $network = strtolower($q['protocol'] ?? 'tcp');
        $tls = $q['security'] ?? (in_array($protocol,['shadowsocks','mieru','vless','vmess'],true) ? 'none' : 'tls');
        if (!in_array($tls,['','none','tls','reality'],true)) self::invalid('不支持此 TLS 安全类型');
        $ns = $network === 'ws' ? ['path'=>$q['path'] ?? '/', 'headers'=>['Host'=>$q['host'] ?? '']] :
            ($network === 'grpc' ? ['serviceName'=>$q['serviceName'] ?? ''] : ['path'=>$q['path'] ?? '/', 'host'=>$q['host'] ?? '']);
        if ($network === 'xhttp') {
            $ns['mode'] = $q['mode'] ?? 'auto';
            if (isset($q['extra'])) { $ns['extra'] = json_decode($q['extra'], true); if (!is_array($ns['extra'])) self::invalid('XHTTP extra 必须是 JSON 对象'); }
        }
        if ($protocol === 'mieru') $ns = ['mtu'=>$q['mtu'] ?? 1400,'multiplexing'=>$q['multiplexing'] ?? 'MULTIPLEXING_LOW','handshake_mode'=>$q['handshake-mode'] ?? 'HANDSHAKE_STANDARD','traffic_pattern'=>$q['traffic-pattern'] ?? null];
        $config = ['protocol'=>$protocol,'host'=>trim($parts['host'],'[]'),'port'=>$parts['port'] ?? ($protocol === 'mieru' ? ($q['port'] ?? null) : 443), 'auth'=>$auth,
            'network'=>$network,'network_settings'=>$ns,'tls'=>$tls === 'reality' ? 2 : ($tls === 'tls' ? 1 : 0),
            'tls_settings'=>['server_name'=>$q['sni'] ?? $q['peer'] ?? '', 'allow_insecure'=>self::boolValue($q['insecure'] ?? $q['allowInsecure'] ?? $q['allow_insecure'] ?? 0),'fingerprint'=>$q['fp'] ?? 'chrome',
                'public_key'=>$q['pbk'] ?? '', 'short_id'=>$q['sid'] ?? ''],
            'flow'=>$q['flow'] ?? null, 'obfs'=>$q['obfs'] ?? null, 'obfs_password'=>$q['obfs-password'] ?? null,
            'congestion_control'=>$q['congestion_control'] ?? 'bbr','udp_relay_mode'=>$q['udp_relay_mode'] ?? 'native',
            'disable_sni'=>self::boolValue($q['disable_sni'] ?? 0),'zero_rtt_handshake'=>self::boolValue($q['zero_rtt_handshake'] ?? 0)];
        if ($protocol === 'shadowsocks') {
            $pair = isset($parts['pass']) ? $user . ':' . $password : self::decode64($user);
            $pair = explode(':',$pair,2);
            if (count($pair) !== 2) self::invalid('Shadowsocks 链接缺少加密方式或密码');
            $config['cipher'] = $pair[0]; $config['auth'] = ['password'=>$pair[1]];
            if (!empty($q['plugin'])) {
                $plugin = self::pluginParts($q['plugin']);
                if (!in_array(array_shift($plugin),['obfs-local','simple-obfs'],true)) self::invalid('暂不支持此 Shadowsocks 插件');
                $options = []; foreach ($plugin as $option) { $kv=explode('=',$option,2); if (count($kv)!==2) self::invalid('插件参数无效'); $options[$kv[0]]=$kv[1]; }
                if (array_diff(array_keys($options),['obfs','obfs-host','path']) || ($options['obfs'] ?? '') !== 'http') self::invalid('目前仅支持 Shadowsocks HTTP 混淆插件');
                $config['obfs']='http'; $config['network_settings']=['host'=>$options['obfs-host'] ?? '', 'path'=>$options['path'] ?? '/'];
            }
        }
        return ['name'=>rawurldecode($parts['fragment'] ?? $q['profile'] ?? $parts['host']), 'config'=>self::normalize($config)];
    }

    private static function boolValue($value): int
    {
        if (in_array($value, [1,'1',true,'true'], true)) return 1;
        if (in_array($value, [0,'0',false,'false',''], true)) return 0;
        self::invalid('布尔参数必须为 0/1 或 true/false');
    }

    public static function auth(array $server, string $key, $default)
    {
        return $server['_external_auth'][$key] ?? $default;
    }

    public static function nodesForGroup(int $groupId, bool $visibleOnly = false)
    {
        $query = ServerExternal::query();
        if ($visibleOnly) $query->where('show', 1);
        return $query->get()->filter(function ($node) use ($groupId) {
            return in_array($groupId, $node->group_id, true);
        })->sort(function ($a, $b) use ($groupId) {
            $left = $a->group_sort[(string)$groupId] ?? $a->sort;
            $right = $b->group_sort[(string)$groupId] ?? $b->sort;
            return ($left <=> $right) ?: ($a->id <=> $b->id);
        })->values();
    }

    public static function available(User $user): array
    {
        if (!$user->group_id || !DB::connection()->getSchemaBuilder()->hasTable('v2_server_external')) return [];
        $servers = [];
        foreach (self::nodesForGroup((int)$user->group_id, true) as $node) {
            $c = $node->config;
            $server = array_merge([
                'network'=>'tcp','network_settings'=>[],'tls'=>0,'tls_settings'=>[], 'flow'=>'','encryption'=>'none',
                'cipher'=>null,'obfs'=>null,'obfs_password'=>null,'up_mbps'=>0,'down_mbps'=>0,'disable_sni'=>0,
                'zero_rtt_handshake'=>0,'congestion_control'=>'bbr','udp_relay_mode'=>'native',
            ], $c, [
                'id'=>-(int)$node->id, 'type'=>$c['protocol'],'name'=>$node->name,'source'=>'external',
                'version'=>2, 'created_at'=>$node->created_at,'updated_at'=>$node->updated_at,
                '_external_auth'=>$c['auth'], 'group_id'=>$node->group_id, 'sort'=>$node->sort,
            ]);
            unset($server['auth']);
            if ($server['network'] === 'http') $server['network_settings']['Host'] = $server['network_settings']['host'] ?? '';
            $server['server_name']=$server['tls_settings']['server_name'] ?? '';
            $server['insecure']=$server['allow_insecure']=$server['tls_settings']['allow_insecure'] ?? 0;
            if ($c['protocol']==='vmess') {
                $server['tlsSettings']=['serverName'=>$server['server_name'],'allowInsecure'=>$server['insecure']] + $server['tls_settings'];
                $server['networkSettings']=$server['network_settings'];
            }
            if ($c['protocol']==='shadowsocks') {
                $server['obfs-host']=$c['network_settings']['host'] ?? '';
                $server['obfs-path']=$c['network_settings']['path'] ?? '/';
                $server['obfs_settings']=['host'=>$server['obfs-host'],'path'=>$server['obfs-path']];
            }
            $servers[]=$server;
        }
        return $servers;
    }
}
