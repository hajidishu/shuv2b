<?php

namespace Tests\P1;

use App\Utils\StripeCurrency;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class StripeCurrencyTest extends TestCase
{
    public function testTwoDecimalCurrenciesKeepExistingCnyCentConversion()
    {
        $usd = StripeCurrency::quote(['total_amount' => 12345], 'USD', 0.14);
        $this->assertSame(['amount' => 1728, 'currency' => 'usd'], $usd);

        $eur = StripeCurrency::quote(['total_amount' => 10000], 'EUR', 0.92);
        $this->assertSame(['amount' => 9200, 'currency' => 'eur'], $eur);
    }

    public function testZeroDecimalCurrenciesConvertToWholeTargetCurrencyUnits()
    {
        $jpy = StripeCurrency::quote(['total_amount' => 12345], 'JPY', 0.14);
        $this->assertSame(['amount' => 17, 'currency' => 'jpy'], $jpy);

        $krw = StripeCurrency::quote(['total_amount' => 10000], 'KRW', 190.5);
        $this->assertSame(['amount' => 19050, 'currency' => 'krw'], $krw);
    }

    public function testIskAndUgxUseTwoDecimalApiAmountsWithWholeCurrencyOnly()
    {
        $isk = StripeCurrency::quote(['total_amount' => 12345], 'ISK', 0.14);
        $this->assertSame(['amount' => 1700, 'currency' => 'isk'], $isk);

        $ugx = StripeCurrency::quote(['total_amount' => 12345], 'UGX', 0.14);
        $this->assertSame(['amount' => 1700, 'currency' => 'ugx'], $ugx);
    }

    public function testFrozenQuoteDoesNotNeedExchangeRateOrConfiguredCurrency()
    {
        $quote = StripeCurrency::quote([
            'total_amount' => 10000,
            'gateway_amount_minor' => 1234,
            'gateway_currency' => 'JPY',
        ], 'USD', null);

        $this->assertSame(['amount' => 1234, 'currency' => 'jpy'], $quote);
    }

    public function testPartialFrozenQuoteIsRejected()
    {
        $this->expectException(InvalidArgumentException::class);
        StripeCurrency::quote([
            'total_amount' => 10000,
            'gateway_amount_minor' => 1234,
        ], 'USD', 1.0);
    }

    public function testIdempotencyOptionsUseDistinctOperationSuffixes()
    {
        $order = ['idempotency_key' => 'order-key'];

        $this->assertSame(['idempotency_key' => 'order-key'], StripeCurrency::idempotencyOptions($order));
        $this->assertSame(['idempotency_key' => 'order-key:payment-intent'], StripeCurrency::idempotencyOptions($order, 'payment-intent'));
        $this->assertSame([], StripeCurrency::idempotencyOptions([]));
    }
}
