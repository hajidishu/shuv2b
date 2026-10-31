<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EmailAddress
{
    public static function normalize($value, bool $storage = false): string
    {
        if (!is_string($value)) self::invalid();
        $value = trim($value);
        Validator::make(['email' => $value], ['email' => 'required|string|email:strict|max:254'])->validate();
        $offset = strrpos($value, '@');
        if ($offset === false) self::invalid();
        $local = substr($value, 0, $offset); $domain = substr($value, $offset + 1);
        if ($domain === '') self::invalid();
        if ($domain[0] !== '[' && function_exists('idn_to_ascii')) {
            // Match the existing SwiftMailer IDN encoder; never strip accents ourselves.
            $info = []; $ascii = idn_to_ascii($domain, 0, INTL_IDNA_VARIANT_UTS46, $info);
            if ($ascii === false || ($info['errors'] ?? 0) !== 0) self::invalid();
            $domain = $ascii;
        } elseif (preg_match('/[^\x00-\x7F]/', $domain)) self::invalid();
        $result = $local . '@' . strtolower($domain);
        if ($storage && mb_strlen($result, 'UTF-8') > 64) {
            throw ValidationException::withMessages(['email' => '邮箱域名规范化后超过本站 64 字符限制']);
        }
        return $result;
    }

    public static function key($value): string
    {
        // Preserve the application's ASCII case-insensitive login policy, without
        // accent folding, Unicode local-part rewriting, or dot/plus alias removal.
        return strtolower(self::normalize($value));
    }

    public static function hash($value): string { return hash('sha256', self::key($value)); }

    public static function candidates($value): array
    {
        $normalized = self::normalize($value); $key = self::key($value);
        $candidates = [trim($value), $normalized, $key];
        $at = strrpos($normalized, '@'); $domain = substr($normalized, $at + 1);
        if ($domain[0] !== '[' && function_exists('idn_to_utf8')) {
            $unicode = idn_to_utf8($domain, 0, INTL_IDNA_VARIANT_UTS46);
            if (is_string($unicode)) {
                $candidates[] = substr($normalized, 0, $at + 1) . $unicode;
                $candidates[] = strtolower(substr($normalized, 0, $at + 1)) . $unicode;
            }
        }
        return array_values(array_unique($candidates));
    }

    private static function invalid(): void { throw ValidationException::withMessages(['email' => '邮箱格式无效']); }
}
