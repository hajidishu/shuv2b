<?php

namespace App\Services;

use App\Utils\CacheKey;
use App\Utils\Helper;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class AuthService
{
    private $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function generateAuthData(Request $request, array $context = [])
    {
        // A password may have been checked before recovery acquired the row.
        // Recheck that exact credential snapshot before publishing a session.
        return DB::transaction(function () use ($request, $context) {
            $current = User::where('id', $this->user->id)->lockForUpdate()->first();
            if (!$current || $current->banned) abort(403, '账号不可用');
            foreach (['password', 'password_algo', 'password_salt', 'is_admin', 'is_staff'] as $field) {
                if (array_key_exists($field, $this->user->getAttributes()) &&
                    (string)$current->$field !== (string)$this->user->$field) abort(403, '账号信息已更新，请重新登录');
            }
            $this->user = $current;
            return $this->issueSession($request, $context);
        }, 3);
    }

    private function issueSession(Request $request, array $context)
    {
        $guid = Helper::guid();
        $authData = JWT::encode([
            'id' => $this->user->id,
            'session' => $guid,
        ], config('app.key'), 'HS256');
        $saved = self::addSession($this->user->id, $guid, array_merge([
            'ip' => $request->ip(),
            'login_at' => time(),
            'ua' => $request->userAgent(),
            'auth_data' => $authData,
            'login_method' => 'password',
        ], array_intersect_key($context, array_flip(['login_method', 'passkey_id']))));
        if (!$saved) abort(503, '登录会话暂时不可用，请重试');
        return [
            'token' => $this->user->token,
            'is_admin' => $this->user->is_admin,
            'auth_data' => $authData
        ];
    }

    public static function decryptAuthData($jwt)
    {
        if (!is_string($jwt) || $jwt === '') return false;
        try {
            $data = (array)JWT::decode($jwt, new Key(config('app.key'), 'HS256'));
            if (!isset($data['id'], $data['session']) || !is_scalar($data['id']) ||
                !ctype_digit((string)$data['id']) || (int)$data['id'] < 1 ||
                !is_string($data['session'])) return false;
            // Old identity caches may outlive a revoked session. Never use them
            // to authorize a request, including during a rolling upgrade.
            if (!self::checkSession($data['id'], $data['session'])) return false;
            $user = User::select(['id', 'email', 'is_admin', 'is_staff', 'banned'])->find($data['id']);
            if (!$user || $user->banned) return false;
            return $user->only(['id', 'email', 'is_admin', 'is_staff']);
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function checkSession($userId, $session)
    {
        $sessions = (array)Cache::get(CacheKey::get("USER_SESSIONS", $userId), []);
        return isset($sessions[$session]);
    }

    private static function addSession($userId, $guid, $meta)
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $userId);
        return Cache::lock($cacheKey . '_MUTEX', 10)->block(3, function () use ($cacheKey, $guid, $meta) {
            $sessions = (array)Cache::get($cacheKey, []);
            $sessions[$guid] = $meta;
            return Cache::put($cacheKey, $sessions);
        });
    }

    public function getSessions()
    {
        return (array)Cache::get(CacheKey::get("USER_SESSIONS", $this->user->id), []);
    }

    public function removeSession($sessionId)
    {
        return DB::transaction(function () use ($sessionId) {
            User::where('id', $this->user->id)->lockForUpdate()->first();
            return $this->removeCachedSession($sessionId);
        }, 3);
    }

    private function removeCachedSession($sessionId)
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $this->user->id);
        return Cache::lock($cacheKey . '_MUTEX', 10)->block(3, function () use ($cacheKey, $sessionId) {
            $sessions = (array)Cache::get($cacheKey, []);
            $jwt = $sessions[$sessionId]['auth_data'] ?? null;
            unset($sessions[$sessionId]);
            if (!Cache::put($cacheKey, $sessions)) return false;
            if (is_string($jwt)) Cache::forget($jwt);
            return true;
        });
    }

    public function removeAllSession()
    {
        return DB::transaction(function () {
            User::where('id', $this->user->id)->lockForUpdate()->first();
            return $this->removeCachedSessions();
        }, 3);
    }

    private function removeCachedSessions()
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $this->user->id);
        return Cache::lock($cacheKey . '_MUTEX', 10)->block(3, function () use ($cacheKey) {
            $sessions = (array)Cache::get($cacheKey, []);
            foreach ($sessions as $meta) {
                if (isset($meta['auth_data'])) Cache::forget($meta['auth_data']);
            }
            return Cache::forget($cacheKey);
        });
    }

    public static function issueQuickLoginCode($authorization): string
    {
        $identity = self::decryptAuthData($authorization);
        if (!$identity) abort(403, '未登录或登陆已过期');
        return DB::transaction(function () use ($identity, $authorization) {
            $user = User::where('id', $identity['id'])->lockForUpdate()->first();
            if (!$user || !self::decryptAuthData($authorization)) abort(403, '未登录或登陆已过期');
            $code = Helper::guid();
            // Binding to the issuing session also covers single-session logout,
            // recovery, administrator revocation and credential removal.
            if (!Cache::put(CacheKey::get('TEMP_TOKEN', $code), [
                'user_id' => (int)$user->id, 'authorization' => $authorization,
            ], 60)) abort(503, '快速登录暂时不可用');
            return $code;
        }, 3);
    }

    public static function redeemQuickLoginCode(Request $request, $code): array
    {
        if (!is_string($code) || !preg_match('/\A[a-zA-Z0-9-]{16,128}\z/', $code)) abort(422, 'Token error');
        $key = CacheKey::get('TEMP_TOKEN', $code);
        $context = Cache::get($key);
        // Legacy user-id-only codes expire naturally after 60 seconds. Never
        // accept them after an upgrade because their issuing session is unknown.
        if (!is_array($context) || !is_int($context['user_id'] ?? null) || !is_string($context['authorization'] ?? null)) abort(422, 'Token error');
        return DB::transaction(function () use ($request, $key, $context) {
            $user = User::where('id', $context['user_id'])->lockForUpdate()->first();
            if (!$user || $user->banned) abort(403, '账号不可用');
            $identity = self::decryptAuthData($context['authorization']);
            if (!$identity || (int)$identity['id'] !== (int)$user->id) abort(422, 'Token error');
            $auth = new self($user);
            $source = null;
            foreach ($auth->getSessions() as $session) {
                if (($session['auth_data'] ?? null) === $context['authorization']) {
                    $source = $session;
                    break;
                }
            }
            if (!is_array($source)) abort(422, 'Token error');
            // Preserve provenance through every redemption. A legacy session
            // cannot be relabelled as a known password login during an upgrade.
            $source['login_method'] = $source['login_method'] ?? 'legacy';
            // User lock serializes recovery, source-session removal and all
            // redemptions. SET NX additionally prevents replay across retries.
            if (!Cache::get($key) || !Cache::add($key . '_USED', true, 60)) abort(422, 'Token error');
            Cache::forget($key);
            return $auth->generateAuthData($request, $source);
        }, 3);
    }
}
