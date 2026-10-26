<?php

namespace App\Services;

use App\Models\User;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\Attestation\AuthenticatorData;

class PasskeyService
{
    private $challenges;

    public function __construct(PasskeyChallenge $challenges)
    {
        $this->challenges = $challenges;
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode($value, int $maximum = 65536): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > (int)ceil($maximum * 4 / 3) + 4 ||
            !preg_match('/\A[A-Za-z0-9_-]+\z/', $value)) abort(422, 'Passkey 数据格式不正确');
        $bytes = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if ($bytes === false || strlen($bytes) > $maximum || self::encode($bytes) !== $value) abort(422, 'Passkey 数据格式不正确');
        return $bytes;
    }

    public function capabilities(): array
    {
        return [
            'enabled' => PasskeySettings::enabled() && method_exists(WebAuthn::class, 'setAllowedOrigins') && PasskeySchema::installed(),
            'rp_id' => PasskeySettings::enabled() ? PasskeySettings::rpId(config('v2board.passkey_rp_id')) : null,
            'rp_name' => (string)config('v2board.app_name', 'V2Board'),
            'related_origins' => true,
            'well_known_url' => PasskeySettings::configured() ? 'https://' . PasskeySettings::rpId(config('v2board.passkey_rp_id')) . '/.well-known/webauthn' : null,
        ];
    }

    private function prepare(Request $request, bool $requireOrigin = true): string
    {
        if (!$requireOrigin && !$request->header('Origin')) {
            if (!PasskeySettings::enabled()) abort(422, 'Passkey 尚未启用或域名配置不完整');
            $origin = '';
        } else $origin = PasskeySettings::requestOrigin($request);
        if (!class_exists(WebAuthn::class) || !PasskeySchema::installed()) abort(503, '请先安装 Passkey 依赖并升级数据库');
        if (!method_exists(WebAuthn::class, 'setAllowedOrigins')) abort(503, '请运行 composer install 安装 Passkey ROR 适配后重启 PHP 和队列');
        return $origin;
    }

    private function library(): WebAuthn
    {
        $web = new WebAuthn((string)config('v2board.app_name', 'V2Board'), PasskeySettings::rpId(config('v2board.passkey_rp_id')), ['none'], true);
        $web->setAllowedOrigins(PasskeySettings::allowedOrigins());
        return $web;
    }

    private function nonce(Request $request): string
    {
        $nonce = $request->input('client_nonce');
        if (strlen(self::decode($nonce, 32)) !== 32) abort(422, 'Passkey 请求已失效');
        return hash('sha256', $nonce);
    }

    private function owner(Request $request): User
    {
        $header = $request->header('Authorization');
        $identity = AuthService::decryptAuthData($header);
        if (!$identity) abort(403, '未登录或登陆已过期');
        $user = User::find($identity['id']);
        if (!$user || $user->banned) abort(403, '未登录或登陆已过期');
        return $user;
    }

    private function sessionHash(Request $request): string
    {
        return hash('sha256', (string)$request->header('Authorization'));
    }

    // Call while holding the user row lock shared with revokeUser(). A request
    // that passed owner() before recovery must not register after recovery.
    private function requireActiveSession(Request $request, User $locked): void
    {
        $identity = AuthService::decryptAuthData($request->header('Authorization'));
        if (!$identity || (int)$identity['id'] !== (int)$locked->id || $locked->banned) {
            abort(403, '未登录或登陆已过期');
        }
    }

    private function mapping(User $user, bool $create = false)
    {
        $rp = PasskeySettings::rpId(config('v2board.passkey_rp_id'));
        $mapping = DB::table('v2_passkey_user')->where('user_id', $user->id)->where('rp_id', $rp)->first();
        if (!$mapping && $create) {
            $id = DB::table('v2_passkey_user')->insertGetId([
                'user_id' => $user->id, 'rp_id' => $rp, 'user_handle' => self::encode(random_bytes(32)), 'created_at' => time(),
            ]);
            $mapping = DB::table('v2_passkey_user')->where('id', $id)->first();
        }
        return $mapping;
    }

    private function active($mapping)
    {
        return DB::table('v2_passkey_credential')->where('passkey_user_id', $mapping->id)->whereNull('revoked_at');
    }

    private function ceremony(Request $request, string $purpose, string $origin, WebAuthn $web, $user = null, array $extra = []): array
    {
        return array_merge([
            'purpose' => $purpose, 'origin' => $origin,
            'rp_id' => PasskeySettings::rpId(config('v2board.passkey_rp_id')),
            'nonce' => $this->nonce($request), 'challenge' => self::encode($web->getChallenge()->getBinaryString()),
            'user_id' => $user ? (int)$user->id : null, 'session' => $user ? $this->sessionHash($request) : null,
        ], $extra);
    }

    private function context(Request $request, string $purpose, $user = null): array
    {
        $origin = $this->prepare($request);
        $context = $this->challenges->consume($request->input('transaction_id'));
        if (($context['purpose'] ?? '') !== $purpose || ($context['origin'] ?? '') !== $origin ||
            ($context['rp_id'] ?? '') !== PasskeySettings::rpId(config('v2board.passkey_rp_id')) ||
            !hash_equals($context['nonce'] ?? '', $this->nonce($request)) ||
            ($user && ((int)$context['user_id'] !== (int)$user->id || !hash_equals($context['session'] ?? '', $this->sessionHash($request))))) {
            abort(422, 'Passkey 验证已失效，请重新开始');
        }
        return $context;
    }

    private function credential(Request $request, array $context, bool $registration): array
    {
        $credential = $request->input('credential');
        if (!is_array($credential) || ($credential['type'] ?? '') !== 'public-key' || !is_array($credential['response'] ?? null)) abort(422, 'Passkey 数据格式不正确');
        $id = self::decode($credential['id'] ?? null, 1023);
        if (!hash_equals($id, self::decode($credential['rawId'] ?? null, 1023))) abort(422, 'Passkey 数据格式不正确');
        $raw = self::decode($credential['response']['clientDataJSON'] ?? null, 8192);
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['origin'] ?? null) !== $context['origin'] ||
            ($data['type'] ?? null) !== ($registration ? 'webauthn.create' : 'webauthn.get') ||
            ($data['crossOrigin'] ?? false) !== false) abort(422, 'Passkey 验证失败');
        return [$id, $raw, $credential['response'], $credential];
    }

    public function loginOptions(Request $request): array
    {
        $origin = $this->prepare($request);
        $this->challenges->limit('login:' . $request->ip(), 30, 60);
        $audience = $request->input('audience', 'user');
        if (!in_array($audience, ['user', 'admin'], true)) abort(422, '登录入口不正确');
        $web = $this->library();
        $options = $web->getGetArgs([], 120, true, true, true, true, true, true);
        $context = $this->ceremony($request, 'login', $origin, $web, null, ['audience' => $audience]);
        return ['transaction_id' => $this->challenges->issue($context), 'publicKey' => $options->publicKey];
    }

    public function loginVerify(Request $request): array
    {
        $this->challenges->limit('login-verify:' . $request->ip(), 60, 60);
        $context = $this->context($request, 'login');
        return $this->assertion($request, $context, true);
    }

    private function assertion(Request $request, array $context, bool $login): array
    {
        [$id, $clientData, $response] = $this->credential($request, $context, false);
        $candidate = DB::table('v2_passkey_credential')->where('credential_hash', hash('sha256', $id))->first();
        $mapping = $candidate ? DB::table('v2_passkey_user')->where('id', $candidate->passkey_user_id)->first() : null;
        if (!$mapping || $mapping->rp_id !== $context['rp_id'] || (!$login && (int)$mapping->user_id !== (int)$context['user_id'])) abort(422, 'Passkey 验证失败');
        return DB::transaction(function () use ($request, $context, $login, $id, $clientData, $response, $candidate, $mapping) {
            $user = User::where('id', $mapping->user_id)->lockForUpdate()->first();
            $key = DB::table('v2_passkey_credential')->where('id', $candidate->id)->lockForUpdate()->first();
            if (!$user || $user->banned || !$key || $key->revoked_at || !hash_equals($key->credential_id, self::encode($id)) ||
                ($login && ($context['audience'] ?? '') === 'admin' && !$user->is_admin)) abort(422, 'Passkey 验证失败');
            $handle = $response['userHandle'] ?? null;
            if (($login && $handle === null) || ($handle !== null && !hash_equals(self::decode($mapping->user_handle, 32), self::decode($handle, 64)))) abort(422, 'Passkey 验证失败');
            $binary = self::decode($response['authenticatorData'] ?? null, 16384);
            try {
                $authenticator = new AuthenticatorData($binary);
                $eligible = (bool)$authenticator->getIsBackupEligible();
                if ($eligible !== (bool)$key->backup_eligible || ($authenticator->getIsBackup() && !$eligible)) abort(422, 'Passkey 验证失败');
                $web = $this->library();
                $web->processGet($clientData, $binary, self::decode($response['signature'] ?? null, 2048), $key->public_key,
                    self::decode($context['challenge'], 64), $eligible ? null : (int)$key->sign_count, true, true);
                $counter = (int)($web->getSignatureCounter() ?? 0);
            } catch (\lbuchs\WebAuthn\WebAuthnException $error) {
                abort(422, 'Passkey 验证失败');
            }
            if ($eligible && $counter && $counter <= (int)$key->sign_count) Log::notice('Passkey counter risk', ['user_id' => $user->id, 'credential_id' => $key->id]);
            DB::table('v2_passkey_credential')->where('id', $key->id)->update([
                'sign_count' => max((int)$key->sign_count, $counter), 'backed_up' => $authenticator->getIsBackup(), 'last_used_at' => time(),
            ]);
            if (!$login) return ['verified' => true];
            $user->last_login_at = time();
            $user->save();
            return (new AuthService($user))->generateAuthData($request, ['login_method' => 'passkey', 'passkey_id' => (int)$key->id]);
        }, 3);
    }

    public function listing(Request $request): array
    {
        $this->prepare($request, false);
        $user = $this->owner($request);
        $mapping = $this->mapping($user);
        $keys = $mapping ? $this->active($mapping)->orderBy('id')->get(['id', 'name', 'created_at', 'last_used_at', 'backup_eligible', 'backed_up'])->toArray() : [];
        return ['credentials' => $keys, 'limit' => 10];
    }

    private function action(Request $request, User $user): array
    {
        $action = $request->input('action');
        if (!in_array($action, ['register', 'rename', 'delete', 'admin_revoke'], true)) abort(422, '操作不正确');
        $target = $request->input('target_id');
        if ($action === 'register') return [$action, null];
        if (!is_int($target) && !(is_string($target) && ctype_digit($target))) abort(422, '操作目标不正确');
        if ((int)$target < 1) abort(422, '操作目标不正确');
        if ($action === 'admin_revoke') {
            if (!$user->is_admin || !User::where('id', $target)->exists()) abort(422, '操作目标不正确');
        } else {
            $mapping = $this->mapping($user);
            if (!$mapping || !$this->active($mapping)->where('id', $target)->exists()) abort(422, '操作目标不正确');
        }
        return [$action, (int)$target];
    }

    private function grant(Request $request, User $user, string $origin, string $action, $target): array
    {
        return ['reauth_token' => $this->challenges->issue([
            'purpose' => 'grant', 'user_id' => (int)$user->id, 'session' => $this->sessionHash($request),
            'origin' => $origin, 'action' => $action, 'target_id' => $target,
            'rp_id' => PasskeySettings::rpId(config('v2board.passkey_rp_id')),
        ]), 'expires_in' => PasskeyChallenge::TTL];
    }

    public function passwordReauth(Request $request): array
    {
        $origin = $this->prepare($request);
        $user = $this->owner($request);
        $this->challenges->limit('reauth:' . $user->id . ':' . $request->ip(), 5, 300);
        [$action, $target] = $this->action($request, $user);
        $password = $request->input('password');
        if (!is_string($password) || strlen($password) > 1024 || !Helper::multiPasswordVerify($user->password_algo, $user->password_salt, $password, $user->password)) abort(422, '身份验证失败');
        return $this->grant($request, $user, $origin, $action, $target);
    }

    public function reauthOptions(Request $request): array
    {
        $origin = $this->prepare($request);
        $user = $this->owner($request);
        $this->challenges->limit('reauth-options:' . $user->id, 20, 60);
        [$action, $target] = $this->action($request, $user);
        $mapping = $this->mapping($user);
        $ids = $mapping ? $this->active($mapping)->pluck('credential_id')->all() : [];
        if (!$ids) abort(422, '尚未绑定 Passkey，请使用密码验证');
        $web = $this->library();
        $options = $web->getGetArgs(array_map(function ($id) { return self::decode($id, 1023); }, $ids), 120, true, true, true, true, true, true);
        return ['transaction_id' => $this->challenges->issue($this->ceremony($request, 'reauth', $origin, $web, $user, ['action' => $action, 'target_id' => $target])), 'publicKey' => $options->publicKey];
    }

    public function reauthVerify(Request $request): array
    {
        $user = $this->owner($request);
        $this->challenges->limit('reauth-verify:' . $user->id, 20, 60);
        $context = $this->context($request, 'reauth', $user);
        $this->assertion($request, $context, false);
        return $this->grant($request, $user, $context['origin'], $context['action'], $context['target_id']);
    }

    private function requireGrant(Request $request, User $user, string $action, $target = null): void
    {
        $context = $this->challenges->consume($request->input('reauth_token'));
        if (($context['purpose'] ?? '') !== 'grant' || (int)$context['user_id'] !== (int)$user->id ||
            !hash_equals($context['session'] ?? '', $this->sessionHash($request)) || $context['origin'] !== $this->prepare($request) ||
            $context['rp_id'] !== PasskeySettings::rpId(config('v2board.passkey_rp_id')) || $context['action'] !== $action || $context['target_id'] !== $target) abort(422, '请重新验证身份');
    }

    public function registerOptions(Request $request): array
    {
        $origin = $this->prepare($request);
        $user = $this->owner($request);
        $this->requireGrant($request, $user, 'register');
        return DB::transaction(function () use ($request, $origin, $user) {
            $locked = User::where('id', $user->id)->lockForUpdate()->first();
            if (!$locked || $locked->banned) abort(422, '账号不可用');
            $this->requireActiveSession($request, $locked);
            $mapping = $this->mapping($locked, true);
            if ($this->active($mapping)->count() >= 10) abort(422, '最多绑定 10 个 Passkey');
            $web = $this->library();
            $ids = $this->active($mapping)->pluck('credential_id')->all();
            $options = $web->getCreateArgs(self::decode($mapping->user_handle, 32), $locked->email, $locked->email, 120, true, true, null,
                array_map(function ($id) { return self::decode($id, 1023); }, $ids));
            return ['transaction_id' => $this->challenges->issue($this->ceremony($request, 'register', $origin, $web, $locked, ['mapping_id' => (int)$mapping->id])), 'publicKey' => $options->publicKey];
        }, 3);
    }

    private function name(Request $request): string
    {
        $name = $request->input('name');
        if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 80) abort(422, 'Passkey 名称需为 1 至 80 个字符');
        return trim($name);
    }

    public function registerVerify(Request $request): array
    {
        $user = $this->owner($request);
        $context = $this->context($request, 'register', $user);
        [$id, $clientData, $response, $credential] = $this->credential($request, $context, true);
        try {
            $result = $this->library()->processCreate($clientData, self::decode($response['attestationObject'] ?? null, 49152), self::decode($context['challenge'], 64), true, true, false);
        } catch (\lbuchs\WebAuthn\WebAuthnException $error) { abort(422, 'Passkey 绑定验证失败'); }
        if (!hash_equals($id, $result->credentialId) || ($result->isBackedUp && !$result->isBackupEligible)) abort(422, 'Passkey 绑定验证失败');
        $name = $this->name($request);
        $transports = $credential['transports'] ?? [];
        if (!is_array($transports) || count($transports) > 8) abort(422, 'Passkey 数据格式不正确');
        foreach ($transports as $transport) if (!is_string($transport) || !in_array($transport, ['usb', 'nfc', 'ble', 'internal', 'hybrid'], true)) abort(422, 'Passkey 数据格式不正确');
        return DB::transaction(function () use ($request, $user, $context, $id, $result, $name, $transports) {
            $locked = User::where('id', $user->id)->lockForUpdate()->first();
            if (!$locked || $locked->banned) abort(422, '账号不可用');
            $this->requireActiveSession($request, $locked);
            $mapping = $this->mapping($locked);
            if (!$mapping || (int)$mapping->id !== $context['mapping_id'] || $this->active($mapping)->count() >= 10) abort(422, 'Passkey 绑定已失效或达到数量上限');
            if (DB::table('v2_passkey_credential')->where('credential_hash', hash('sha256', $id))->exists()) abort(422, '此 Passkey 已经登记，请使用新的凭据');
            $keyId = DB::table('v2_passkey_credential')->insertGetId([
                'passkey_user_id' => $mapping->id, 'credential_hash' => hash('sha256', $id), 'credential_id' => self::encode($id),
                'public_key' => $result->credentialPublicKey, 'name' => $name, 'sign_count' => (int)($result->signatureCounter ?? 0),
                'backup_eligible' => $result->isBackupEligible, 'backed_up' => $result->isBackedUp,
                'transports' => json_encode(array_values(array_unique($transports))), 'created_at' => time(),
            ]);
            Log::info('Passkey registered', ['user_id' => $locked->id, 'credential_id' => $keyId]);
            return ['id' => $keyId];
        }, 3);
    }

    public function rename(Request $request): array
    {
        $this->prepare($request);
        $user = $this->owner($request);
        [$action, $target] = $this->action($request, $user);
        if ($action !== 'rename') abort(422, '操作不正确');
        $this->requireGrant($request, $user, $action, $target);
        $name = $this->name($request);
        DB::table('v2_passkey_credential')->where('id', $target)->whereNull('revoked_at')->update(['name' => $name]);
        return ['success' => true];
    }

    public function delete(Request $request): array
    {
        $this->prepare($request);
        $user = $this->owner($request);
        [$action, $target] = $this->action($request, $user);
        if ($action !== 'delete') abort(422, '操作不正确');
        $this->requireGrant($request, $user, $action, $target);
        $signOut = DB::transaction(function () use ($request, $user, $target) {
            User::where('id', $user->id)->lockForUpdate()->first();
            DB::table('v2_passkey_credential')->where('id', $target)->whereNull('revoked_at')->update(['revoked_at' => time()]);
            $auth = new AuthService($user);
            $signOut = false;
            // Pre-fix quick logins had no provenance. Revoke those unknown
            // sessions too, rather than leaving an already-created bypass alive.
            foreach ($auth->getSessions() as $id => $session) if ((int)($session['passkey_id'] ?? 0) === $target || ($session['login_method'] ?? 'legacy') === 'legacy') {
                if (hash_equals((string)($session['auth_data'] ?? ''), (string)$request->header('Authorization'))) $signOut = true;
                $auth->removeSession($id);
            }
            return $signOut;
        }, 3);
        Log::info('Passkey revoked', ['user_id' => $user->id, 'credential_id' => $target]);
        return ['success' => true, 'sign_out' => $signOut];
    }

    public function adminRevoke(Request $request): array
    {
        $this->prepare($request);
        $actor = $this->owner($request);
        [$action, $target] = $this->action($request, $actor);
        if ($action !== 'admin_revoke') abort(422, '操作不正确');
        $this->requireGrant($request, $actor, $action, $target);
        return ['revoked' => self::revokeUser($target, $actor->email), 'sign_out' => (int)$actor->id === $target];
    }

    public static function revokeUser(int $userId, string $actor = 'console'): int
    {
        return DB::transaction(function () use ($userId, $actor) {
            $user = User::where('id', $userId)->lockForUpdate()->first();
            if (!$user) abort(422, '用户不存在');
            $ids = DB::table('v2_passkey_user')->where('user_id', $userId)->pluck('id');
            $count = DB::table('v2_passkey_credential')->whereIn('passkey_user_id', $ids)->whereNull('revoked_at')->update(['revoked_at' => time()]);
            (new AuthService($user))->removeAllSession();
            Log::info('User passkeys revoked', ['user_id' => $userId, 'actor' => $actor, 'count' => $count]);
            return $count;
        }, 3);
    }

    public static function revokeOnRecovery(User $user): void
    {
        if (PasskeySchema::installed()) self::revokeUser((int)$user->id, 'account-recovery');
        else (new AuthService($user))->removeAllSession();
    }

    public static function purgeUser(int $userId): void
    {
        if (!PasskeySchema::installed()) return;
        $ids = DB::table('v2_passkey_user')->where('user_id', $userId)->pluck('id');
        DB::table('v2_passkey_credential')->whereIn('passkey_user_id', $ids)->delete();
        DB::table('v2_passkey_user')->where('user_id', $userId)->delete();
    }
}
