<?php

namespace App\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class ImageCaptchaService
{
    public const TTL = 120;
    public const PURPOSES = ['register', 'email'];

    public static function available(): bool
    {
        foreach (['imagecreatetruecolor', 'imagecolorallocate', 'imagefill', 'imageline', 'imagesetpixel', 'imagestring', 'imagecopyresampled', 'imagerotate', 'imagecopy', 'imagepng', 'imagesx', 'imagesy', 'imagedestroy'] as $function) {
            if (!function_exists($function)) return false;
        }
        return true;
    }

    public function create(Request $request): array
    {
        if (CaptchaService::provider() !== 'image') abort(404);
        if (!self::available()) abort(503, '图片验证码需要启用 PHP GD 扩展');
        $purpose = $request->input('purpose');
        $previous = $request->input('previous_id');
        if (!in_array($purpose, self::PURPOSES, true) || ($previous !== null && !$this->validId($previous))) abort(422, '验证码请求无效');
        $this->limit($request, 'create', 20);
        // Rendering happens before saving, so a failed image never leaves a usable challenge.
        $id = bin2hex(random_bytes(32));
        $code = $this->generateCode();
        $image = $this->render($code);
        if ($previous !== null) {
            $this->locked($previous, function () use ($previous, $purpose) {
                $old = Cache::get($this->key($previous));
                if (is_array($old) && ($old['purpose'] ?? null) === $purpose && !Cache::forget($this->key($previous))) abort(503, '验证码服务暂时不可用');
            });
        }
        $record = ['digest' => $this->digest($id, $purpose, $code), 'purpose' => $purpose, 'expires_at' => now()->timestamp + self::TTL];
        if (!Cache::put($this->key($id), $record, self::TTL)) abort(503, '验证码服务暂时不可用');
        return ['id' => $id, 'image' => 'data:image/png;base64,' . base64_encode($image), 'expires_in' => self::TTL];
    }

    public function verify(Request $request, string $purpose): void
    {
        $this->limit($request, 'verify', 60);
        $token = $request->input('captcha_data');
        // Keep captcha_data a string, compatible with remote providers and form-encoded clients.
        if (!is_string($token) || strlen($token) > 80 || !preg_match('/\A([a-f0-9]{64}):([A-Za-z0-9]{6})\z/', $token, $parts)) abort(422, __('Invalid code is incorrect'));
        [$unused, $id, $answer] = $parts;
        $valid = $this->locked($id, function () use ($id, $answer, $purpose) {
            $record = Cache::get($this->key($id));
            if (!is_array($record)) return false;
            // Both successful and incorrect guesses consume the challenge under the same lock.
            if (!Cache::forget($this->key($id))) abort(503, '验证码服务暂时不可用');
            return ($record['purpose'] ?? null) === $purpose
                && ($record['expires_at'] ?? 0) > now()->timestamp
                && is_string($record['digest'] ?? null)
                && hash_equals($record['digest'], $this->digest($id, $purpose, strtoupper($answer)));
        });
        if (!$valid) abort(422, __('Invalid code is incorrect'));
    }

    private function key(string $id): string { return 'IMAGE_CAPTCHA_' . $id; }
    private function validId($id): bool { return is_string($id) && preg_match('/\A[a-f0-9]{64}\z/', $id) === 1; }

    private function digest(string $id, string $purpose, string $answer): string
    {
        $key = config('app.key');
        if (!is_string($key) || strlen($key) < 16) abort(503, '验证码服务配置不完整');
        return hash_hmac('sha256', $id . ':' . $purpose . ':' . $answer, $key);
    }

    private function locked(string $id, callable $operation)
    {
        try { return Cache::lock('IMAGE_CAPTCHA_LOCK_' . $id, 10)->block(1, $operation); }
        catch (LockTimeoutException $error) { abort(503, '验证码服务繁忙，请刷新后重试'); }
    }

    private function limit(Request $request, string $action, int $maximum): void
    {
        $key = 'IMAGE_CAPTCHA_RATE_' . $action . '_' . hash('sha256', (string)$request->ip());
        try {
            Cache::lock($key . '_LOCK', 10)->block(1, function () use ($key, $maximum) {
                if (RateLimiter::tooManyAttempts($key, $maximum)) abort(429, __('Too many requests, please try again later.'));
                RateLimiter::hit($key, 60);
            });
        } catch (LockTimeoutException $error) { abort(429, __('Too many requests, please try again later.')); }
    }

    protected function generateCode(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $code = '';
        for ($i = 0; $i < 6; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        return $code;
    }

    protected function render(string $code): string
    {
        $image = imagecreatetruecolor(180, 56);
        if (!$image) abort(503, '验证码图片生成失败');
        $level = ob_get_level();
        try {
            $background = imagecolorallocate($image, 247, 249, 252);
            imagefill($image, 0, 0, $background);
            for ($i = 0; $i < 5; $i++) {
                $color = imagecolorallocate($image, random_int(155, 205), random_int(165, 210), random_int(180, 220));
                imageline($image, random_int(0, 179), random_int(0, 55), random_int(0, 179), random_int(0, 55), $color);
            }
            for ($i = 0; $i < 6; $i++) {
                $glyph = imagecreatetruecolor(28, 40);
                $small = imagecreatetruecolor(9, 15);
                imagefill($small, 0, 0, $background);
                imagefill($glyph, 0, 0, $background);
                $ink = imagecolorallocate($small, random_int(30, 70), random_int(45, 85), random_int(70, 115));
                imagestring($small, 5, 0, 0, $code[$i], $ink);
                imagecopyresampled($glyph, $small, 3, 3, 0, 0, 21, 34, 9, 15);
                $rotated = imagerotate($glyph, random_int(-12, 12), $background);
                imagecopy($image, $rotated, 5 + $i * 28, random_int(5, 9), 0, 0, imagesx($rotated), imagesy($rotated));
                imagedestroy($small); imagedestroy($glyph); imagedestroy($rotated);
            }
            for ($i = 0; $i < 80; $i++) imagesetpixel($image, random_int(0, 179), random_int(0, 55), imagecolorallocate($image, random_int(110, 210), 150, 180));
            ob_start();
            if (!imagepng($image)) abort(503, '验证码图片生成失败');
            return (string)ob_get_clean();
        } finally {
            while (ob_get_level() > $level) ob_end_clean();
            imagedestroy($image);
        }
    }
}
