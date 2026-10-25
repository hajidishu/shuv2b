<?php

namespace App\Services;

use Illuminate\Http\Request;

class PasskeySettings
{
    public static function rpId($value): ?string
    {
        if (!is_string($value)) return null;
        $value = strtolower(trim($value));
        if ($value === 'localhost') return $value;
        if (strlen($value) > 253 || filter_var($value, FILTER_VALIDATE_IP) ||
            !preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+\z/', $value)) return null;
        return $value;
    }

    public static function origin($value): ?string
    {
        if (!is_string($value) || preg_match('/[\s\\\\]/', $value)) return null;
        $parts = parse_url($value);
        if (!$parts || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['query']) || isset($parts['fragment']) || !empty($parts['path']) && $parts['path'] !== '/') return null;
        $host = strtolower($parts['host'] ?? '');
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!self::rpId($host) || ($scheme !== 'https' && !($scheme === 'http' && $host === 'localhost'))) return null;
        $port = $parts['port'] ?? null;
        if ($port !== null && ($port < 1 || $port > 65535)) return null;
        return $scheme . '://' . $host . ($port && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) ? ':' . $port : '');
    }

    public static function validate(array $values): bool
    {
        $rp = self::rpId($values['passkey_rp_id'] ?? '');
        $origins = $values['passkey_origins'] ?? [];
        if (!$rp || !is_array($origins) || !$origins || count($origins) > 30) return false;
        foreach ($origins as $origin) {
            $normalized = self::origin($origin);
            if (!$normalized) return false;
            $host = parse_url($normalized, PHP_URL_HOST);
            if ($rp === 'localhost' && $host !== 'localhost') return false;
            if ($rp !== 'localhost' && parse_url($normalized, PHP_URL_SCHEME) !== 'https') return false;
        }
        return true;
    }

    public static function configured(): bool
    {
        return self::validate([
            'passkey_rp_id' => config('v2board.passkey_rp_id'),
            'passkey_origins' => config('v2board.passkey_origins', []),
        ]);
    }

    public static function enabled(): bool
    {
        return (bool)config('v2board.passkey_enabled', 0) && self::configured();
    }

    public static function requestOrigin(Request $request): string
    {
        if (!self::enabled()) abort(422, 'Passkey 尚未启用或域名配置不完整');
        $origin = self::origin($request->header('Origin'));
        $allowed = self::allowedOrigins();
        if (!$origin || !in_array($origin, $allowed, true)) abort(422, '当前前端域名未被允许使用 Passkey');
        return $origin;
    }

    public static function allowedOrigins(): array
    {
        return array_values(array_unique(array_filter(array_map([self::class, 'origin'], (array)config('v2board.passkey_origins', [])))));
    }

    public static function relatedOriginsDocument(): array
    {
        $rp = self::rpId(config('v2board.passkey_rp_id'));
        $allowed = self::allowedOrigins();
        // Natural RP suffixes need no related-origin fetch. Excluding them keeps
        // scarce browser origin-label slots available for associated domains.
        $related = array_values(array_filter($allowed, function ($origin) use ($rp) {
            $host = parse_url($origin, PHP_URL_HOST);
            return $rp && $host !== $rp && substr($host, -strlen('.' . $rp)) !== '.' . $rp;
        }));
        return ['origins' => $related ?: $allowed];
    }
}
