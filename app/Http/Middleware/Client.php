<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;

class Client
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $token = $request->input('token');
        // JSON preserves numeric/boolean/array types. Never let PDO bind those
        // against a string token column and trigger database numeric coercion.
        if (!is_string($token) || empty($token) || strlen($token) > 512) {
            abort(403, 'token is null');
        }
        $submethod = (int)config('v2board.show_subscribe_method', 0);
        switch ($submethod) {
            case 0:
                break;
            case 1:
                if (!Cache::has("otpn_{$token}")) {
                    abort(403, 'token is error');
                }
                $usertoken = Cache::pull("otpn_{$token}");
                if (!is_string($usertoken) || empty($usertoken) || strlen($usertoken) > 512) abort(403, 'token is error');
                Cache::forget("otp_{$usertoken}");
                $token = $usertoken;
                break;
            case 2:
                $usertoken = Cache::get("totp_{$token}");
                if (!$usertoken) {
                    $timestep = (int)config('v2board.show_subscribe_expire', 5) * 60;
                    if ($timestep < 60) abort(403, 'token is error');
                    $counter = floor(time() / $timestep);
                    $counterBytes = pack('N*', 0) . pack('N*', $counter);
                    $idhash = Helper::base64DecodeUrlSafe($token);
                    if (!is_string($idhash) || strpos($idhash, ':') === false) {
                        abort(403, 'token is error');
                    }
                    $parts = explode(':', $idhash, 2);
                    [$userid, $clienthash] = $parts;
                    if (!ctype_digit($userid) || (int)$userid < 1 || !$clienthash) {
                        abort(403, 'token is error');
                    }
                    $user = User::where('id', $userid)->select('token')->first();
                    if (!$user) {
                        abort(403, 'token is error');
                    }
                    $usertoken = $user->token;
                    if (!is_string($usertoken) || empty($usertoken)) abort(403, 'token is error');
                    $hash = hash_hmac('sha1', $counterBytes, $usertoken, false);
                    if ($clienthash !== $hash) {
                        abort(403, 'token is error');
                    }
                    Cache::put("totp_{$token}", $usertoken, $timestep);
                }
                $token = $usertoken;
                break;
            default:
                break;
        }
        if (!is_string($token) || empty($token) || strlen($token) > 512) abort(403, 'token is error');
        $user = User::where('token', $token)->first();
        if (!$user || !is_string($user->token) || !hash_equals($user->token, $token)) {
            abort(403, 'token is error');
        }
        $request->merge([
            'user' => $user
        ]);
        return $next($request);
    }
}
