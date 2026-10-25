<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class PasskeyChallenge
{
    const TTL = 300;

    public function issue(array $context): string
    {
        $id = PasskeyService::encode(random_bytes(32));
        $context['expires_at'] = time() + self::TTL;
        if (!Cache::put('PASSKEY_' . hash('sha256', $id), $context, self::TTL)) abort(503, 'Passkey 暂时不可用');
        return $id;
    }

    public function consume($id): array
    {
        if (!is_string($id) || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $id)) abort(422, 'Passkey 验证已失效，请重新开始');
        $key = 'PASSKEY_' . hash('sha256', $id);
        $context = Cache::get($key);
        // Cache::add maps to atomic SET NX on Redis. Only one request may claim
        // the context, even if several requests read it before it is deleted.
        if (!is_array($context) || !Cache::add($key . '_USED', true, self::TTL)) abort(422, 'Passkey 验证已失效，请重新开始');
        Cache::forget($key);
        if (($context['expires_at'] ?? 0) <= time()) abort(422, 'Passkey 验证已失效，请重新开始');
        return $context;
    }

    public function limit(string $scope, int $maximum, int $seconds): void
    {
        $key = 'PASSKEY_RATE_' . hash('sha256', $scope);
        Cache::add($key, 0, $seconds);
        $count = Cache::increment($key);
        if ($count === false || $count === null) abort(503, 'Passkey 暂时不可用');
        if ($count > $maximum) abort(429, '操作过于频繁，请稍后再试');
    }
}
