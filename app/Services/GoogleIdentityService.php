<?php

namespace App\Services;

use App\Models\User;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Contracts\Cache\LockTimeoutException;

class GoogleIdentityService
{
    const TTL = 300;
    private $verifier;

    public function __construct(GoogleTokenVerifier $verifier) { $this->verifier = $verifier; }

    private function limit(string $scope, int $count = 20, int $seconds = 60): void
    {
        app(PasskeyChallenge::class)->limit('google:' . $scope, $count, $seconds);
    }

    private function authorization(Request $request): string
    {
        $jwt = $request->input('auth_data') ?? $request->header('Authorization');
        if (!is_string($jwt) || $jwt === '') abort(403, '请重新登录');
        return $jwt;
    }

    private function owner(Request $request): User
    {
        $identity = AuthService::decryptAuthData($this->authorization($request));
        if (!$identity) abort(403, '请重新登录');
        $user = User::where('id', $identity['id'])->lockForUpdate()->first();
        // Recovery/removal can revoke the session while this request waits for
        // the user row. Recheck after acquiring the shared account lock.
        $current = AuthService::decryptAuthData($this->authorization($request));
        if (!$user || $user->banned || !$current || (int)$current['id'] !== (int)$user->id) abort(403, '账号不可用');
        return $user;
    }

    private function credentialVersion(User $user): string
    {
        return hash('sha256', json_encode([$user->password, $user->password_algo, $user->password_salt]));
    }

    private function reauth(Request $request, User $user, string $action): void
    {
        $this->limit('reauth:' . $user->id . ':' . $request->ip(), 5, 300);
        $password = $request->input('password');
        if ($request->input('reauth_token') && $password !== null) abort(422, '请选择一种身份验证方式');
        if ($request->input('reauth_token')) {
            app(PasskeyService::class)->authorizeGoogleAction($request, $user, 'google_' . $action);
            return;
        }
        if (!is_string($password) || strlen($password) > 1024 || !Helper::multiPasswordVerify($user->password_algo, $user->password_salt, $password, $user->password)) abort(422, '身份验证失败');
    }

    private function clientNonce(Request $request): string
    {
        $nonce = $request->input('client_nonce');
        if (!is_string($nonce) || !preg_match('/\A[A-Za-z0-9_-]{43,128}\z/', $nonce)) abort(422, '登录请求无效，请重新开始');
        return $nonce;
    }

    private function issue(array $context): string
    {
        $id = PasskeyService::encode(random_bytes(32));
        $context['expires_at'] = time() + self::TTL;
        if (!Cache::put('GOOGLE_IDENTITY_' . hash('sha256', $id), $context, self::TTL)) abort(503, 'Google 登录暂时不可用');
        return $id;
    }

    private function context(Request $request, string $purpose, $id, bool $consume = true): array
    {
        if (!is_string($id) || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $id)) abort(422, '验证已过期，请重新授权');
        $key = 'GOOGLE_IDENTITY_' . hash('sha256', $id);
        $context = Cache::get($key);
        if (!is_array($context) || ($context['expires_at'] ?? 0) <= time() || ($context['purpose'] ?? '') !== $purpose ||
            ($context['origin'] ?? '') !== GoogleSettings::origin($request) ||
            ($context['client_id'] ?? '') !== config('v2board.google_client_id') ||
            !hash_equals($context['client_hash'] ?? '', hash('sha256', $this->clientNonce($request)))) abort(422, '验证已过期，请重新授权');
        if ($consume) {
            if (!Cache::add($key . '_USED', true, self::TTL)) abort(422, '此验证已经使用，请重新授权');
            Cache::forget($key);
        }
        return $context;
    }

    public function options(Request $request, bool $bind = false): array
    {
        $origin = GoogleSettings::origin($request);
        if (!GoogleIdentitySchema::installed()) abort(503, '请先运行面板更新以安装第三方登录数据表');
        $this->limit('options:' . $request->ip());
        $context = ['purpose' => $bind ? 'bind' : 'login', 'origin' => $origin,
            'client_id' => config('v2board.google_client_id'), 'client_hash' => hash('sha256', $this->clientNonce($request)),
            'nonce' => PasskeyService::encode(random_bytes(32))];
        if ($bind) {
            $context = DB::transaction(function () use ($request, $context) {
                $user = $this->owner($request);
                $this->reauth($request, $user, 'bind');
                if (DB::table('v2_social_identity')->where('user_id', $user->id)->where('provider', 'google')->exists()) abort(409, '此账号已经绑定 Google，请先解除原绑定');
                return $context + ['user_id' => (int)$user->id, 'session_hash' => hash('sha256', $this->authorization($request)), 'credential_version' => $this->credentialVersion($user)];
            }, 3);
        }
        return ['transaction_id' => $this->issue($context), 'nonce' => $context['nonce'], 'client_id' => $context['client_id'], 'expires_in' => self::TTL];
    }

    private function subjectHash(array $claims): string { return hash('sha256', 'google:' . $claims['subject']); }

    private function insert(User $user, array $claims): int
    {
        return (int)DB::table('v2_social_identity')->insertGetId([
            'user_id' => $user->id, 'provider' => 'google', 'subject_hash' => $this->subjectHash($claims),
            'subject' => $claims['subject'], 'email' => $claims['email'], 'created_at' => time(), 'last_login_at' => null,
        ]);
    }

    private function claims(Request $request, array $context): array
    {
        $this->limit('verify:' . $request->ip());
        return $this->verifier->verify($request->input('credential'), $context['nonce'], $context['client_id']);
    }

    public function login(Request $request): array
    {
        $context = $this->context($request, 'login', $request->input('transaction_id'));
        $claims = $this->claims($request, $context);
        $mapping = DB::table('v2_social_identity')->where('subject_hash', $this->subjectHash($claims))->first();
        if ($mapping) return DB::transaction(function () use ($request, $claims, $mapping) {
            $user = User::where('id', $mapping->user_id)->lockForUpdate()->first();
            $current = DB::table('v2_social_identity')->where('id', $mapping->id)->where('user_id', $mapping->user_id)->where('subject_hash', $this->subjectHash($claims))->lockForUpdate()->first();
            if (!$user || $user->banned || !$current) abort(403, '账号或 Google 绑定已不可用');
            DB::table('v2_social_identity')->where('id', $current->id)->update(['last_login_at' => time(), 'email' => $claims['email']]);
            $user->last_login_at = time();
            $user->save();
            return ['status' => 'authenticated', 'auth' => (new AuthService($user))->generateAuthData($request, ['login_method' => 'google', 'social_identity_id' => (int)$current->id])];
        }, 3);
        // Matching an email is not permission to take over an existing account.
        if (EmailIdentity::find($claims['email'])) return ['status' => 'link_required', 'email' => $claims['email']];
        $this->registrationAllowed();
        if (strlen($claims['email']) > 64) abort(422, '此邮箱长度超过本站支持范围');
        // Issue a separate purpose-bound ticket; a consumed login challenge can
        // never be reused as a registration or binding proof.
        $context['purpose'] = 'register';
        return ['status' => 'registration_required', 'email' => $claims['email'],
            'registration_token' => $this->issue($context + ['claims' => $claims]),
            'email_verification_required' => !$claims['verified_email']];
    }

    private function registrationAllowed(): void
    {
        if (!GoogleSettings::enabled() || !(int)config('v2board.google_register_enabled', 1) || (int)config('v2board.stop_register', 0)) abort(403, '当前不允许通过 Google 注册新账号');
    }

    public function register(Request $request): array
    {
        $id = $request->input('registration_token');
        $this->registrationAllowed();
        $context = $this->context($request, 'register', $id, false);
        $this->limit('register:' . $request->ip());
        try {
            return Cache::lock('GOOGLE_REGISTER_' . hash('sha256', $id), 30)->block(3, function () use ($request, $context, $id) {
                $context = $this->context($request, 'register', $id, false);
                $this->registrationAllowed();
                $request->merge(['email' => $context['claims']['email']]);
                $auth = app(RegistrationService::class)->register($request, $context['claims']['verified_email'], !$context['claims']['verified_email'], function ($user) use ($context) {
                    $mapping = $this->insert($user, $context['claims']);
                    return ['login_method' => 'google', 'social_identity_id' => $mapping];
                });
                Cache::forget('GOOGLE_IDENTITY_' . hash('sha256', $id));
                return ['status' => 'authenticated', 'auth' => $auth];
            });
        } catch (QueryException $error) {
            if ((string)$error->getCode() === '23000') abort(409, '邮箱或 Google 身份已登记，请重新登录');
            throw $error;
        } catch (LockTimeoutException $error) { abort(409, '注册正在处理中，请勿重复提交'); }
    }

    public function bind(Request $request): array
    {
        $context = $this->context($request, 'bind', $request->input('transaction_id'));
        $claims = $this->claims($request, $context);
        try {
            return DB::transaction(function () use ($request, $context, $claims) {
                $user = $this->owner($request);
                if ((int)$user->id !== $context['user_id'] || !hash_equals($context['session_hash'], hash('sha256', $this->authorization($request))) ||
                    !hash_equals($context['credential_version'], $this->credentialVersion($user))) abort(403, '账号验证状态已改变，请重新开始绑定');
                if (DB::table('v2_social_identity')->where('subject_hash', $this->subjectHash($claims))->exists() ||
                    DB::table('v2_social_identity')->where('user_id', $user->id)->where('provider', 'google')->exists()) abort(409, 'Google 身份已被绑定，请先解除原绑定');
                $this->insert($user, $claims);
                return ['success' => true, 'email' => $claims['email']];
            }, 3);
        } catch (QueryException $error) {
            if ((string)$error->getCode() === '23000') abort(409, 'Google 身份已被绑定');
            throw $error;
        }
    }

    public function listing(Request $request): array
    {
        $user = $this->owner($request);
        $mapping = GoogleIdentitySchema::installed() ? DB::table('v2_social_identity')->where('user_id', $user->id)->where('provider', 'google')->first() : null;
        return ['enabled' => GoogleSettings::enabled(), 'site_email' => $user->email,
            'identity' => $mapping ? ['email' => $mapping->email, 'created_at' => $mapping->created_at, 'last_login_at' => $mapping->last_login_at] : null];
    }

    public function unbind(Request $request): array
    {
        return DB::transaction(function () use ($request) {
            $user = $this->owner($request);
            $this->reauth($request, $user, 'unbind');
            if (!GoogleIdentitySchema::installed()) return ['success' => true, 'sign_out' => false];
            $mapping = DB::table('v2_social_identity')->where('user_id', $user->id)->where('provider', 'google')->lockForUpdate()->first();
            if (!$mapping) return ['success' => true, 'sign_out' => false];
            DB::table('v2_social_identity')->where('id', $mapping->id)->delete();
            $auth = new AuthService($user);
            $signOut = false;
            foreach ($auth->getSessions() as $id => $session) if ((int)($session['social_identity_id'] ?? 0) === (int)$mapping->id) {
                if (hash_equals((string)($session['auth_data'] ?? ''), $this->authorization($request))) $signOut = true;
                if (!$auth->removeSession($id)) abort(503, '会话撤销失败，请稍后重试');
            }
            return ['success' => true, 'sign_out' => $signOut];
        }, 3);
    }

    public static function purgeUser(int $userId): void
    {
        if (GoogleIdentitySchema::installed()) DB::table('v2_social_identity')->where('user_id', $userId)->where('provider', 'google')->delete();
    }
}
