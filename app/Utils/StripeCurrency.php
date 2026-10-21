<?php

namespace App\Utils;

use InvalidArgumentException;

/**
 * Converts V2Board CNY cents into the minor-unit amount expected by Stripe.
 */
class StripeCurrency
{
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG',
        'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    // Stripe requires two decimal places for these legacy currencies, with no fractional unit.
    private const LEGACY_TWO_DECIMAL_ZERO_ONLY = ['ISK', 'UGX'];

    public static function hasFrozenQuote(array $order): bool
    {
        return array_key_exists('gateway_amount_minor', $order) || array_key_exists('gateway_currency', $order);
    }

    /**
     * @param array $order May contain gateway_amount_minor and gateway_currency frozen at checkout.
     * @param string $configuredCurrency ISO 4217 currency configured for this Stripe method.
     * @param mixed $exchangeRate Target major currency units per CNY.
     * @return array{amount:int,currency:string}
     */
    public static function quote(array $order, string $configuredCurrency, $exchangeRate): array
    {
        $hasAmount = array_key_exists('gateway_amount_minor', $order);
        $hasCurrency = array_key_exists('gateway_currency', $order);
        if ($hasAmount || $hasCurrency) {
            if (!$hasAmount || !$hasCurrency || !is_int($order['gateway_amount_minor']) ||
                $order['gateway_amount_minor'] < 0 || !is_string($order['gateway_currency']) ||
                !preg_match('/\A[A-Za-z]{3}\z/', $order['gateway_currency'])) {
                throw new InvalidArgumentException('Invalid frozen Stripe quote');
            }
            return [
                'amount' => $order['gateway_amount_minor'],
                'currency' => strtolower($order['gateway_currency']),
            ];
        }

        $currency = strtoupper(trim($configuredCurrency));
        if (!preg_match('/\A[A-Z]{3}\z/', $currency) || !is_numeric($exchangeRate) ||
            !is_finite((float)$exchangeRate) || (float)$exchangeRate <= 0) {
            throw new InvalidArgumentException('Invalid Stripe currency quote inputs');
        }

        $rawCents = $order['total_amount'] ?? null;
        if ((!is_int($rawCents) && !(is_string($rawCents) && preg_match('/\A[0-9]+\z/', $rawCents))) ||
            (float)$rawCents < 0) {
            throw new InvalidArgumentException('Invalid CNY amount');
        }
        $cnyCents = (float)$rawCents;
        $rate = (float)$exchangeRate;

        if (in_array($currency, self::LEGACY_TWO_DECIMAL_ZERO_ONLY, true)) {
            $majorUnits = floor(($cnyCents * $rate) / 100);
            $minorAmount = $majorUnits * 100;
        } elseif (in_array($currency, self::ZERO_DECIMAL, true)) {
            $minorAmount = floor(($cnyCents * $rate) / 100);
        } else {
            $minorAmount = floor($cnyCents * $rate);
        }

        if (!is_finite($minorAmount) || $minorAmount > PHP_INT_MAX) {
            throw new InvalidArgumentException('Stripe amount is out of range');
        }

        return ['amount' => (int)$minorAmount, 'currency' => strtolower($currency)];
    }

    /**
     * Stripe SDK create methods accept request options as their second argument.
     * StripeALL supplies distinct suffixes because it creates objects on different endpoints.
     */
    public static function idempotencyOptions(array $order, ?string $suffix = null): array
    {
        $key = $order['idempotency_key'] ?? null;
        if (!is_string($key) || $key === '') return [];
        if ($suffix !== null && $suffix !== '') $key .= ':' . $suffix;
        return ['idempotency_key' => $key];
    }
}
