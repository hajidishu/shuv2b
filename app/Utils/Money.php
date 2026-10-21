<?php

namespace App\Utils;

class Money
{
    // Parse gateway decimal amounts without floating point rounding or truncation.
    public static function toCents($amount): ?int
    {
        if (!is_string($amount) && !is_int($amount) && !is_float($amount)) return null;
        if (!preg_match('/\A([0-9]+)(?:\.([0-9]+))?\z/', (string)$amount, $parts)) return null;
        $fraction = $parts[2] ?? '';
        if (strlen($fraction) > 2 && trim(substr($fraction, 2), '0') !== '') return null;
        $digits = ltrim($parts[1] . substr(str_pad($fraction, 2, '0'), 0, 2), '0');
        if ($digits === '') return 0;
        $max = (string)PHP_INT_MAX;
        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) return null;
        return (int)$digits;
    }
}
