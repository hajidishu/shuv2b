<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CaptchaService
{
    public const PROVIDERS = [
        'recaptcha' => [
            'site_key' => 'recaptcha_site_key', 'secret' => 'recaptcha_key',
            'url' => 'https://www.google.com/recaptcha/api/siteverify', 'max_token' => 8192,
        ],
        'turnstile' => [
            'site_key' => 'turnstile_site_key', 'secret' => 'turnstile_secret_key',
            'url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify', 'max_token' => 2048,
        ],
    ];

    public static function provider(?array $values = null): string
    {
        $values = $values ?? (array)config('v2board', []);
        $provider = $values['captcha_provider'] ?? null;
        return is_string($provider) && $provider !== '' ? $provider : (!empty($values['recaptcha_enable']) ? 'recaptcha' : 'none');
    }

    public static function publicSettings(): array
    {
        $provider = self::provider();
        $key = self::PROVIDERS[$provider]['site_key'] ?? null;
        return [
            'captcha_provider' => $provider,
            'is_captcha' => $provider === 'none' ? 0 : 1,
            'captcha_site_key' => $key ? config('v2board.' . $key) : null,
            // Keep Google's historical contract for frontends without Turnstile.
            'is_recaptcha' => $provider === 'recaptcha' ? 1 : 0,
            'recaptcha_site_key' => $provider === 'recaptcha' ? config('v2board.recaptcha_site_key') : null,
        ];
    }

    public function verify(Request $request): void
    {
        $provider = self::provider();
        if ($provider === 'none') return;
        $adapter = self::PROVIDERS[$provider] ?? null;
        $secret = $adapter ? config('v2board.' . $adapter['secret']) : null;
        if (!$adapter || !is_string($secret) || trim($secret) === '') abort(503, '人机验证配置不完整，请联系管理员');
        $token = $request->input('captcha_data', $request->input('recaptcha_data', $request->input('cf-turnstile-response')));
        if (!is_string($token) || trim($token) === '' || strlen($token) > $adapter['max_token']) abort(422, __('Invalid code is incorrect'));
        try {
            $response = Http::asForm()->withOptions(['connect_timeout' => 3])->timeout(8)->post($adapter['url'], [
                'secret' => $secret, 'response' => $token, 'remoteip' => $request->ip(),
            ]);
            $result = $response->json();
        } catch (\Throwable $error) {
            // Never log the token, private key, or HTTP client's request details.
            abort(503, '人机验证服务暂时不可用，请重新验证后重试');
        }
        if (!$response->successful() || !is_array($result)) abort(503, '人机验证服务暂时不可用，请重新验证后重试');
        if (($result['success'] ?? null) !== true) abort(422, __('Invalid code is incorrect'));
    }
}
