<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;

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
        // Version the value format so old workers cannot INCR a new record.
        $key = 'PASSKEY_RATE_V2_' . hash('sha256', $scope);
        try {
            Cache::lock($key . '_MUTEX', 5)->block(2, function () use ($key, $maximum, $seconds) {
                $window = Cache::get($key);
                $now = Carbon::now()->getTimestamp();
                if (!is_array($window) || !isset($window['count'], $window['expires_at']) || $window['expires_at'] <= $now) {
                    $window = ['count' => 0, 'expires_at' => $now + $seconds];
                }
                if ($window['count'] >= $maximum) abort(429, '操作过于频繁，请稍后再试');
                $window['count']++;
                // Always write with a bounded TTL; keep the original fixed
                // deadline, even when a key expires during this operation.
                if (!Cache::put($key, $window, max(1, $window['expires_at'] - $now))) abort(503, 'Passkey 暂时不可用');
            });
        } catch (LockTimeoutException $error) {
            abort(503, 'Passkey 暂时不可用，请重试');
        }
    }
}
