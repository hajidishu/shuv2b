<?php

namespace App\Services;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Cache\LockTimeoutException;

class EmailVerificationService
{
    public const TTL = 300;
    public static function key(string $purpose, $email): string { return 'EMAIL_CHALLENGE_V2_' . $purpose . '_' . EmailAddress::hash($email); }
    private function locked(string $purpose, string $email, callable $operation)
    {
        try { return Cache::lock(self::key($purpose, $email) . '_LOCK', 30)->block(3, $operation); }
        catch (LockTimeoutException $e) { abort(503, '验证码服务繁忙，请稍后重试'); }
    }
    private function applicationKey(): string
    {
        $key = config('app.key');
        if (!is_string($key) || strlen($key) < 16) abort(503, '验证码服务配置不完整');
        return $key;
    }
    private function passwordVersion(User $user): string
    {
        return hash_hmac('sha256', json_encode([$user->password, $user->password_algo, $user->password_salt]), $this->applicationKey());
    }
    private function digest(array $record, string $code): string
    {
        return hash_hmac('sha256', json_encode([$record['schema'] ?? null, $record['purpose'] ?? null, $record['email_hash'] ?? null,
            $record['recipient'] ?? null, $record['user_id'] ?? null, $record['email_version'] ?? null, $record['password_version'] ?? null,
            $record['nonce'] ?? null, $record['expires_at'] ?? null, $code]), $this->applicationKey());
    }
    private function valid($record, string $purpose, string $email, $code): bool
    {
        return is_array($record) && ($record['schema'] ?? null) === 2 && ($record['purpose'] ?? null) === $purpose
            && is_string($record['email_hash'] ?? null) && hash_equals($record['email_hash'], EmailAddress::hash($email))
            && is_string($record['recipient'] ?? null) && is_string($record['nonce'] ?? null)
            && is_int($record['expires_at'] ?? null) && $record['expires_at'] > time()
            && is_string($code) && preg_match('/\A[0-9]{6}\z/', $code) === 1
            && is_string($record['digest'] ?? null) && hash_equals($record['digest'], $this->digest($record, $code));
    }
    private function deliver(string $purpose, string $email, string $recipient, ?User $user = null): void
    {
        $key = self::key($purpose, $email);
        if (Cache::get($key . '_SENT')) abort(500, __('Email verification code has been sent, please request again later'));
        $identity = $user ? EmailIdentity::current($user) : null;
        $code = (string)random_int(100000, 999999);
        $record = ['schema' => 2, 'purpose' => $purpose, 'email_hash' => EmailAddress::hash($email), 'recipient' => $recipient,
            'user_id' => $user ? (int)$user->id : null, 'email_version' => $identity ? $identity->email_version : null,
            'password_version' => $user ? $this->passwordVersion($user) : null, 'nonce' => bin2hex(random_bytes(16)), 'expires_at' => time() + self::TTL];
        $record['digest'] = $this->digest($record, $code);
        if (!Cache::put($key, $record, self::TTL)) abort(503, '验证码服务暂时不可用');
        try {
            SendEmailJob::dispatch(['email' => $recipient, 'subject' => config('v2board.app_name', 'V2Board') . __('Email verification code'),
                'template_name' => 'verify', 'template_value' => ['name' => config('v2board.app_name', 'V2Board'), 'code' => $code, 'url' => config('v2board.app_url')]]);
        } catch (\Throwable $e) { Cache::forget($key); abort(503, '验证码邮件暂时无法发送'); }
        if (!Cache::put($key . '_SENT', time(), 60)) abort(503, '验证码服务暂时不可用');
    }
    public function sendRegistration($email): void
    {
        $email = EmailAddress::normalize($email, true);
        $this->locked('register', $email, function () use ($email) {
            EmailIdentity::assertAvailable($email);
            $this->deliver('register', $email, $email);
        });
    }
    public function sendRecovery($email): void
    {
        $email = EmailAddress::normalize($email);
        if (!EmailIdentity::installed()) abort(503, '请先完成邮箱身份数据库升级');
        $this->locked('recovery', $email, function () use ($email) {
            DB::transaction(function () use ($email) {
                $user = EmailIdentity::find($email, true);
                if (!$user) abort(500, __('This email is not registered in the system'));
                // The user-controlled spelling never becomes the recovery recipient.
                $this->deliver('recovery', $email, EmailAddress::normalize((string)$user->email), $user);
            }, 3);
        });
    }
    public function registrationProof($email, $code): array
    {
        $email = EmailAddress::normalize($email);
        return $this->locked('register', $email, function () use ($email, $code) {
            $attempts = CacheKey::get('REGISTER_EMAIL_CODE_LIMIT', EmailAddress::key($email));
            $failures = (int)Cache::get($attempts, 0);
            if ($failures >= 10) abort(429, '邮箱验证码尝试过多，请稍后重试');
            $record = Cache::get(self::key('register', $email));
            if (!$this->valid($record, 'register', $email, $code)) {
                if (!Cache::put($attempts, $failures + 1, self::TTL)) abort(503, '验证码服务暂时不可用');
                abort(500, __('Incorrect email verification code'));
            }
            return $record;
        });
    }
    public function consumeRegistration($email, array $proof): void
    {
        $email = EmailAddress::normalize($email);
        $this->locked('register', $email, function () use ($email, $proof) {
            $key = self::key('register', $email); $current = Cache::get($key);
            if (!is_array($current) || ($current['nonce'] ?? null) !== $proof['nonce'] || ($current['expires_at'] ?? 0) <= time()
                || !is_string($current['digest'] ?? null) || !hash_equals($current['digest'], $proof['digest'])) abort(500, __('Incorrect email verification code'));
            if (!Cache::forget($key)) abort(503, '验证码服务暂时不可用');
            Cache::forget(CacheKey::get('REGISTER_EMAIL_CODE_LIMIT', EmailAddress::key($email)));
        });
    }
    public function recover($email, $code, $password): void
    {
        $email = EmailAddress::normalize($email);
        if (!is_string($code) || !preg_match('/\A[0-9]{6}\z/', $code) || !is_string($password)) abort(500, __('Incorrect email verification code'));
        if (!EmailIdentity::installed()) abort(503, '请先完成邮箱身份数据库升级');
        $this->locked('recovery', $email, function () use ($email, $code, $password) {
            $key = self::key('recovery', $email); $attemptKey = $key . '_ATTEMPTS';
            $failures = (int)Cache::get($attemptKey, 0);
            if ($failures >= 3) abort(500, __('Reset failed, Please try again later'));
            $record = Cache::get($key);
            if (!$this->valid($record, 'recovery', $email, $code) || !is_int($record['user_id'] ?? null) || $record['user_id'] < 1) {
                if (!Cache::put($attemptKey, $failures + 1, self::TTL)) abort(503, '验证码服务暂时不可用');
                abort(500, __('Incorrect email verification code'));
            }
            DB::transaction(function () use ($key, $attemptKey, $record, $email, $code, $password) {
                // Resolve only the ID bound at issuance, never a collation-based email query.
                $user = User::where('id', $record['user_id'])->lockForUpdate()->first();
                if (!$user) { Cache::forget($key); abort(500, __('Incorrect email verification code')); }
                $current = Cache::get($key);
                if (!$this->valid($current, 'recovery', $email, $code) || $current['nonce'] !== $record['nonce']) abort(500, __('Incorrect email verification code'));
                $identity = EmailIdentity::current($user);
                if (!hash_equals($identity->email_hash, EmailAddress::hash($email)) || !hash_equals($identity->email_version, $record['email_version'])
                    || !hash_equals(EmailAddress::normalize((string)$user->email), $record['recipient']) || !hash_equals($this->passwordVersion($user), $record['password_version'])) {
                    Cache::forget($key); abort(500, __('Incorrect email verification code'));
                }
                // Burn the code before mutation. Database failure requires a fresh challenge.
                if (!Cache::forget($key)) abort(503, '验证码服务暂时不可用');
                $user->password = password_hash($password, PASSWORD_DEFAULT); $user->password_algo = null; $user->password_salt = null;
                if (!$user->save()) abort(500, __('Reset failed'));
                AccountSecurityService::recover($user);
                Cache::forget($attemptKey);
            });
        });
    }
}
