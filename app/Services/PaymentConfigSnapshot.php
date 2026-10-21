<?php
namespace App\Services;
use Illuminate\Encryption\Encrypter;
class PaymentConfigSnapshot
{
    private static function encrypter()
    {
        $key = (string)config('app.key');
        if (strpos($key, 'base64:') === 0) $key = base64_decode(substr($key, 7), true);
        if (!is_string($key) || $key === '') throw new \RuntimeException('支付配置快照需要有效的 APP_KEY');
        return new Encrypter($key, config('app.cipher', 'AES-256-CBC'));
    }
    public static function seal(array $config) { return self::encrypter()->encryptString(json_encode($config, JSON_THROW_ON_ERROR)); }
    public static function open($payload) { return json_decode(self::encrypter()->decryptString($payload), true, 512, JSON_THROW_ON_ERROR); }
}
