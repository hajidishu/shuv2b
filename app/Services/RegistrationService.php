<?php

namespace App\Services;

use App\Models\InviteCode;
use App\Models\Plan;
use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Contracts\Cache\LockTimeoutException;

class RegistrationService
{
    public function register(Request $request, bool $verifiedEmail = false, bool $forceEmailVerify = false, ?callable $identity = null): array
    {
        Validator::make($request->all(), ['email' => 'required|email:strict|max:64', 'password' => 'required|string|min:8|max:1024', 'invite_code' => 'nullable|string|max:128'])->validate();
        $email = $request->input('email');
        $emailKey = strtolower(trim($email));
        if ((int)config('v2board.register_limit_by_ip_enable', 0) &&
            (int)Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip()), 0) >= (int)config('v2board.register_limit_count', 3)) abort(500, __('Register frequently, please try again after :minute minute', ['minute' => config('v2board.register_limit_expire', 60)]));
        app(CaptchaService::class)->verify($request);
        if ((int)config('v2board.email_whitelist_enable', 0) && !Helper::emailSuffixVerify($email, config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))) abort(500, __('Email suffix is not in the Whitelist'));
        if ((int)config('v2board.email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $email)[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) abort(500, __('Gmail alias is not supported'));
        }
        if ((int)config('v2board.stop_register', 0)) abort(500, __('Registration has closed'));
        if ((int)config('v2board.invite_force', 0) && !$request->input('invite_code')) abort(500, __('You must use the invitation code to register'));
        $verify = !$verifiedEmail && ($forceEmailVerify || (int)config('v2board.email_verify', 0));
        $operation = function () use ($request, $email, $emailKey, $verify, $identity) {
            $ipKey = CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip());
            $limited = (int)config('v2board.register_limit_by_ip_enable', 0);
            $count = (int)Cache::get($ipKey, 0);
            if ($limited && $count >= (int)config('v2board.register_limit_count', 3)) abort(500, __('Register frequently, please try again after :minute minute', ['minute' => config('v2board.register_limit_expire', 60)]));
            return DB::transaction(function () use ($request, $email, $emailKey, $verify, $identity, $ipKey, $limited, $count) {
                if (User::where('email', $email)->exists()) abort(500, __('Email already exists'));
                if ($verify) {
                    $attempts = CacheKey::get('REGISTER_EMAIL_CODE_LIMIT', $emailKey);
                    $failures = (int)Cache::get($attempts, 0);
                    if ($failures >= 10) abort(429, '邮箱验证码尝试过多，请稍后重试');
                    $code = $request->input('email_code');
                    $cached = Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $emailKey));
                    if (!is_string($code) || !preg_match('/\A\d{6}\z/', $code) || !is_scalar($cached) || !hash_equals((string)$cached, $code)) {
                        if (!Cache::put($attempts, $failures + 1, 300)) abort(503, '注册服务暂时不可用');
                        abort(500, __('Incorrect email verification code'));
                    }
                }
                $invite = null;
                if ($request->input('invite_code')) $invite = InviteCode::where('code', $request->input('invite_code'))->where('status', 0)->lockForUpdate()->first();
                if ((int)config('v2board.invite_force', 0) && !$invite) abort(500, __('Invalid invitation code'));
                $user = new User();
                $user->email = $email;
                $user->password = password_hash($request->input('password'), PASSWORD_DEFAULT);
                $user->uuid = Helper::guid(true);
                $user->token = Helper::guid();
                if ($invite) $user->invite_user_id = $invite->user_id ?: null;
                if ((int)config('v2board.try_out_plan_id', 0)) {
                    $plan = Plan::find(config('v2board.try_out_plan_id'));
                    if ($plan) {
                        $user->transfer_enable = $plan->transfer_enable * 1073741824;
                        $user->device_limit = $plan->device_limit;
                        $user->plan_id = $plan->id;
                        $user->group_id = $plan->group_id;
                        $user->expired_at = time() + config('v2board.try_out_hour', 1) * 3600;
                        $user->speed_limit = $plan->speed_limit;
                    }
                }
                $user->last_login_at = time();
                if (!$user->save()) abort(500, __('Register failed'));
                if ($invite && !(int)config('v2board.invite_never_expire', 0)) { $invite->status = 1; $invite->save(); }
                $context = $identity ? $identity($user) : [];
                if ($limited && !Cache::put($ipKey, $count + 1, max(1, (int)config('v2board.register_limit_expire', 60)) * 60)) abort(503, '注册服务暂时不可用');
                if ($verify) {
                    Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $emailKey));
                    Cache::forget(CacheKey::get('REGISTER_EMAIL_CODE_LIMIT', $emailKey));
                }
                return (new AuthService($user))->generateAuthData($request, $context);
            }, 3);
        };
        try {
            return Cache::lock('REGISTER_EMAIL_' . hash('sha256', $emailKey), 30)->block(3, function () use ($request, $operation) {
                if (!(int)config('v2board.register_limit_by_ip_enable', 0)) return $operation();
                return Cache::lock('REGISTER_IP_' . hash('sha256', $request->ip()), 30)->block(3, $operation);
            });
        } catch (LockTimeoutException $error) { abort(503, '注册服务繁忙，请稍后重试'); }
    }
}
