<?php

namespace App\Services;

use App\Models\User;
use App\Utils\Helper;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PasswordLoginLimiter
{
    public function throttle(Request $request): void
    {
        // Request::ip() honors only the proxies explicitly trusted by the deployment.
        foreach (['ip:' . hash('sha256', (string)$request->ip()) => 60, 'global' => 1200] as $scope => $maximum) {
            $key = 'PASSWORD_LOGIN_RATE_V1:' . $scope;
            $this->locked($key, function () use ($key, $maximum) {
                $count = (int)Cache::get($key, 0);
                if ($count >= $maximum) abort(429, __('Too many requests, please try again later.'));
                // Fixed one-minute windows; concurrent requests cannot overwrite increments.
                if ($count === 0) Cache::put($key, 1, 60);
                else Cache::increment($key);
            });
        }
    }

    public function verify(?User $user, string $email, string $password): bool
    {
        $verify = function () use ($user, $password) {
            return $user && Helper::multiPasswordVerify($user->password_algo, $user->password_salt, $password, $user->password);
        };
        if (!(int)config('v2board.password_limit_enable', 1)) return $verify();

        $identity = $user ? 'user:' . $user->id : 'email:' . hash('sha256', $email);
        $key = 'PASSWORD_LOGIN_ERRORS_V2:' . $identity;
        return $this->locked($key, function () use ($key, $verify) {
            $count = (int)Cache::get($key, 0);
            if ($count >= (int)config('v2board.password_limit_count', 5)) {
                abort(500, __('There are too many password errors, please try again after :minute minutes.', [
                    'minute' => config('v2board.password_limit_expire', 60)
                ]));
            }
            $valid = $verify();
            if ($valid) Cache::forget($key);
            else Cache::put($key, $count + 1, 60 * (int)config('v2board.password_limit_expire', 60));
            return $valid;
        });
    }

    private function locked(string $key, callable $operation)
    {
        try { return Cache::lock($key . ':lock', 15)->block(3, $operation); }
        catch (LockTimeoutException $error) { abort(429, __('Too many requests, please try again later.')); }
    }
}
