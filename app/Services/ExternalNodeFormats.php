<?php

namespace App\Services;

class ExternalNodeFormats
{
    // These are the transports implemented by our exporters, not a claim about every client version.
    public static function filter(array $servers, string $formatter): array
    {
        $format = class_basename($formatter);
        $basic = ['tcp','ws','grpc']; $uri = ['tcp','ws','grpc','http','httpupgrade','xhttp'];
        $networks = ['vmess'=>$uri,'vless'=>$uri,'trojan'=>$basic];
        if (in_array($format,['Clash','ClashMeta','ClashVerge','ClashNyanpasu','Stash','Singbox','SingboxOld'],true)) {
            $networks = ['vmess'=>$basic,'vless'=>$basic,'trojan'=>$basic];
            if ($format === 'Clash') $networks['vless'] = [];
            if (in_array($format,['ClashMeta','ClashVerge','ClashNyanpasu'],true)) $networks['vless'][] = 'xhttp';
        } elseif ($format === 'Shadowrocket') {
            $networks['vmess'] = $basic;
        } elseif (in_array($format,['Surge','Surfboard','Loon','QuantumultX'],true)) {
            $networks = ['vmess'=>['tcp','ws'],'vless'=>['tcp','ws'],'trojan'=>['tcp','ws']];
            if ($format === 'Surge' || $format === 'Surfboard') $networks['vless'] = [];
        }
        return array_values(array_filter($servers, function ($server) use ($format,$networks) {
            if (!isset($server['_external_auth'])) return true;
            $protocol = $server['type']; $network = $server['network'] ?? 'tcp';
            if (isset($networks[$protocol]) && !in_array($network,$networks[$protocol],true)) return false;
            if ($network === 'xhttp' && !empty($server['network_settings']['extra']) && in_array($format,['ClashMeta','ClashVerge','ClashNyanpasu'],true)) return false;
            if (in_array($format,['Surge','Surfboard','Loon','QuantumultX'],true)) {
                if (strpos($server['name'],'=') !== false) return false;
                $values = [$server['name'],$server['host'],$server['_external_auth'],$server['tls_settings'] ?? [],$server['network_settings'] ?? [],$server['obfs_password'] ?? ''];
                $representable = true;
                array_walk_recursive($values,function ($value) use (&$representable) {
                    if (is_string($value) && (strpbrk($value, ",\"\\") !== false || preg_match('/[\x00-\x1f\x7f]/',$value) || trim($value) !== $value)) $representable = false;
                });
                if (!$representable) return false;
            }
            return true;
        }));
    }
}
