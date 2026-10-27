<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GoogleTokenVerifier
{
    private const CERTS = 'https://www.googleapis.com/oauth2/v3/certs';
    private const CACHE = 'GOOGLE_LOGIN_JWKS_V1';

    private function keys(string $kid): array
    {
        $cached = Cache::get(self::CACHE);
        $contains = function ($data) use ($kid) {
            foreach ((array)($data['keys'] ?? []) as $key) if (($key['kid'] ?? null) === $kid) return true;
            return false;
        };
        if (!is_array($cached) || !$contains($cached)) {
            // Unknown key IDs cannot force an unbounded number of Google requests.
            if (Cache::add(self::CACHE . '_REFRESH', true, 30)) {
                try {
                    $response = Http::withOptions(['connect_timeout' => 3])->timeout(5)->get(self::CERTS);
                    $data = $response->json();
                    if (!$response->successful() || !is_array($data) || !is_array($data['keys'] ?? null) || count($data['keys']) > 10) throw new \RuntimeException();
                    preg_match('/max-age=(\d+)/i', (string)$response->header('Cache-Control'), $match);
                    $ttl = isset($match[1]) ? min(86400, (int)$match[1]) : 300;
                    if ($ttl < 1 || !Cache::put(self::CACHE, $data, $ttl)) throw new \RuntimeException();
                    $cached = $data;
                } catch (\Throwable $error) {
                    abort(503, 'Google 身份验证暂时不可用，请稍后重试');
                }
            } else {
                $cached = Cache::get(self::CACHE);
            }
        }
        if (!is_array($cached) || !$contains($cached)) abort(422, 'Google 身份凭据无效，请重新授权');
        try { return JWK::parseKeySet($cached, 'RS256'); }
        catch (\Throwable $error) { abort(503, 'Google 身份验证暂时不可用'); }
    }

    public function verify($token, string $nonce, string $clientId): array
    {
        if (!is_string($token) || strlen($token) > 8192 || count(explode('.', $token)) !== 3) abort(422, 'Google 身份凭据无效');
        $parts = explode('.', $token);
        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/'), true) ?: '', true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'RS256' || !is_string($header['kid'] ?? null) ||
            strlen($header['kid']) > 128 || !empty($header['crit'])) abort(422, 'Google 身份凭据无效');
        $keys = $this->keys($header['kid']);
        try { $claims = (array)JWT::decode($token, $keys); }
        catch (\Throwable $error) { abort(422, 'Google 身份凭据无效或已过期'); }
        if (!in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true) ||
            ($claims['aud'] ?? null) !== $clientId || (isset($claims['azp']) && $claims['azp'] !== $clientId) ||
            !is_int($claims['exp'] ?? null) || $claims['exp'] <= time() ||
            !is_int($claims['iat'] ?? null) || $claims['iat'] > time() + 60 ||
            !is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce']) ||
            ($claims['email_verified'] ?? null) !== true ||
            !is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 255 || preg_match('/[\x00-\x20]/', $claims['sub']) ||
            !is_string($claims['email'] ?? null) || strlen($claims['email']) > 254 || !filter_var($claims['email'], FILTER_VALIDATE_EMAIL)) abort(422, 'Google 身份凭据校验失败');
        $email = strtolower(trim($claims['email']));
        return ['subject' => $claims['sub'], 'email' => $email,
            'verified_email' => substr($email, -10) === '@gmail.com' || (is_string($claims['hd'] ?? null) && $claims['hd'] !== '')];
    }
}
