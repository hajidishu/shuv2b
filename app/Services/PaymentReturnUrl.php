<?php
namespace App\Services;

class PaymentReturnUrl
{
    const DEFAULT_PATH = '/#/order/{trade_no}';

    // Return a canonical HTTP(S) base without credentials, query or fragment.
    public static function normalizeBase($value, $originOnly = false)
    {
        if (!is_string($value) || $value === '' || strlen($value) > 2048 ||
            preg_match('/[\s\\\\<>"\']/', $value) || !filter_var($value, FILTER_VALIDATE_URL)) return null;
        $parts = parse_url($value);
        if (!$parts || !isset($parts['scheme'], $parts['host']) ||
            !in_array(strtolower($parts['scheme']), ['http', 'https'], true) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) return null;
        $path = $parts['path'] ?? '';
        $decoded = rawurldecode($path);
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $decoded) || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $decoded) ||
            ($originOnly && $path !== '' && $path !== '/')) return null;
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        $authority = $scheme . '://' . strtolower($parts['host']);
        if ($port !== null && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) $authority .= ':' . $port;
        return $authority . rtrim($path, '/');
    }

    public static function validPath($value)
    {
        if (!is_string($value) || strlen($value) > 512 || substr_count($value, '{trade_no}') !== 1 ||
            substr($value, 0, 1) !== '/' || substr($value, 0, 2) === '//') return false;
        $decoded = rawurldecode($value);
        if (substr($decoded, 0, 2) === '//' || preg_match('/[\x00-\x20\x7f\\\\<>"\']/', $decoded) ||
            preg_match('/[{}]/', str_replace('{trade_no}', '', $value))) return false;
        $path = preg_split('/[?#]/', $decoded, 2)[0];
        return !preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $path);
    }

    private static function origin($base)
    {
        $parts = parse_url($base);
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    public static function base()
    {
        $configured = config('v2board.payment_return_url');
        $base = $configured ? self::normalizeBase($configured) : null;
        if ($configured && !$base) abort(422, '支付回跳前端地址配置无效');
        $fallback = self::normalizeBase(config('v2board.app_url')) ?: self::normalizeBase(request()->getSchemeAndHttpHost());
        if (!$base) $base = $fallback;
        if (!$base) abort(422, '无法确定支付回跳地址');
        $allowed = config('v2board.payment_return_origins', []) ?: [];
        if (!is_array($allowed)) abort(422, '支付回跳允许域名配置无效');
        $origins = [self::origin($base)];
        foreach ($allowed as $entry) {
            $entry = self::normalizeBase($entry, true);
            if (!$entry) abort(422, '支付回跳允许域名配置无效');
            $origins[] = $entry;
        }
        $origin = request()->header('Origin');
        if (!$origin) {
            $referer = request()->header('Referer');
            $parts = is_string($referer) ? parse_url($referer) : false;
            if ($parts && isset($parts['scheme'], $parts['host']) && !isset($parts['user']) && !isset($parts['pass'])) {
                $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            }
        }
        $origin = self::normalizeBase($origin, true);
        if ($origin && in_array($origin, $origins, true)) {
            // Preserve a configured subdirectory when its own origin is selected.
            return $origin === self::origin($base) ? $base : $origin;
        }
        return $base;
    }

    public static function make($tradeNo)
    {
        $path = config('v2board.payment_return_path') ?: self::DEFAULT_PATH;
        if (!self::validPath($path)) abort(422, '支付回跳路径必须为站内路径且包含一个 {trade_no}');
        return self::base() . str_replace('{trade_no}', rawurlencode((string)$tradeNo), $path);
    }
}
