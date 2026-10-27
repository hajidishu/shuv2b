<?php

namespace App\Services;

use Illuminate\Http\Request;

class GoogleSettings
{
    public static function validate(array $values): bool
    {
        $client = $values['google_client_id'] ?? null;
        $origins = $values['google_origins'] ?? null;
        if (!is_string($client) || !preg_match('/\A[0-9]+-[A-Za-z0-9_-]+\.apps\.googleusercontent\.com\z/', $client) ||
            !is_array($origins) || !$origins || count($origins) > 100) return false;
        foreach ($origins as $origin) if (!PasskeySettings::origin($origin)) return false;
        return true;
    }

    public static function enabled(): bool
    {
        return (bool)config('v2board.google_login_enabled', 0) && self::validate((array)config('v2board', []));
    }

    public static function publicSettings(): array
    {
        return ['google_login_enabled' => self::enabled(), 'google_client_id' => self::enabled() ? config('v2board.google_client_id') : null];
    }

    public static function origin(Request $request, bool $enabled = true): string
    {
        if ($enabled && !self::enabled()) abort(422, 'Google 登录尚未启用或配置不完整');
        $origin = PasskeySettings::origin($request->header('Origin'));
        $allowed = array_filter(array_map([PasskeySettings::class, 'origin'], (array)config('v2board.google_origins', [])));
        if (!$origin || !in_array($origin, $allowed, true)) abort(403, '当前前端来源不允许使用 Google 登录');
        return $origin;
    }
}
