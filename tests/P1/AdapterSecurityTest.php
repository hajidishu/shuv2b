<?php

namespace Tests\P1;

use App\Payments\BEasyPaymentUSDT;
use App\Payments\EPayQrcode;
use App\Payments\Epusdt;
use App\Payments\Paytaro;
use App\Payments\PaytaroQR;
use PHPUnit\Framework\TestCase;

class AdapterSecurityTest extends TestCase
{
    private $serverSecret;

    protected function tearDown(): void
    {
        if ($this->serverSecret === null) {
            unset($_SERVER['HTTP_X_APP_SECRET']);
        } else {
            $_SERVER['HTTP_X_APP_SECRET'] = $this->serverSecret;
        }
    }

    public function testEmptySecretsCannotAuthenticateCallbacksEvenWithMatchingEmptyKeySignatures()
    {
        $bepusdtParams = ['order_id' => 'order-1', 'status' => 2, 'trade_id' => 'tx-1'];
        $bepusdtParams['signature'] = $this->beasySignature($bepusdtParams, '');
        $this->assertFalse((new BEasyPaymentUSDT(['bepusdt_apitoken' => '']))->notify($bepusdtParams));

        $epusdtParams = ['order_id' => 'order-1', 'status' => 2, 'trade_id' => 'tx-1'];
        $epusdtParams['signature'] = $this->epusdtSignature($epusdtParams, '');
        $this->assertFalse((new Epusdt(['epusdt_token' => '']))->notify($epusdtParams));

        $epayParams = ['pid' => 'merchant-1', 'out_trade_no' => 'order-1', 'trade_no' => 'tx-1', 'trade_status' => 'TRADE_SUCCESS'];
        $epayParams['sign'] = $this->epayQrSignature($epayParams, '');
        $this->assertFalse((new EPayQrcode(['pid' => 'merchant-1', 'key' => '']))->notify($epayParams));

        $paytaroParams = ['out_trade_no' => 'order-1', 'trade_no' => 'tx-1', 'trade_status' => 'TRADE_SUCCESS'];
        $paytaroParams['sign'] = $this->sortedMd5Signature($paytaroParams, '');
        $this->assertFalse((new Paytaro(['pid' => 'app-1', 'key' => '']))->notify($paytaroParams));

        $this->setCallbackSecret('shared-secret');
        $this->assertFalse((new PaytaroQR(['pid' => 'app-1', 'key' => '']))->notify(['merchant_no' => 'order-1', 'status' => 'PAID', 'callback_no' => 'tx-1']));
    }

    public function testMalformedSignatureAndNestedCallbackValuesReturnFalse()
    {
        $this->assertFalse((new BEasyPaymentUSDT(['bepusdt_apitoken' => 'secret']))->notify([
            'order_id' => 'order-1', 'status' => 2, 'trade_id' => 'tx-1', 'signature' => ['not-a-string'],
        ]));
        $this->assertFalse((new Epusdt(['epusdt_token' => 'secret']))->notify([
            'order_id' => 'order-1', 'status' => 2, 'trade_id' => 'tx-1', 'signature' => [],
        ]));
        $this->assertFalse((new EPayQrcode(['pid' => 'merchant-1', 'key' => 'secret']))->notify([
            'pid' => 'merchant-1', 'out_trade_no' => 'order-1', 'trade_no' => 'tx-1',
            'trade_status' => 'TRADE_SUCCESS', 'sign' => [],
        ]));
        $this->assertFalse((new Paytaro(['pid' => 'app-1', 'key' => 'secret']))->notify([
            'out_trade_no' => 'order-1', 'trade_no' => 'tx-1', 'trade_status' => 'TRADE_SUCCESS', 'sign' => [],
        ]));

        $this->setCallbackSecret('secret');
        $this->assertFalse((new PaytaroQR(['pid' => 'app-1', 'key' => 'secret']))->notify([
            'merchant_no' => 'order-1', 'status' => 'PAID', 'callback_no' => ['tx-1'],
        ]));
    }

    public function testWrongSignaturesAndNonArrayInputsReturnFalse()
    {
        $this->assertFalse((new BEasyPaymentUSDT(['bepusdt_apitoken' => 'secret']))->notify('not-an-array'));
        $this->assertFalse((new BEasyPaymentUSDT(['bepusdt_apitoken' => 'secret']))->notify([
            'order_id' => 'order-1', 'status' => 2, 'trade_id' => 'tx-1', 'signature' => str_repeat('0', 32),
        ]));

        $this->assertFalse((new Epusdt(['epusdt_token' => 'secret']))->notify('not-an-array'));
        $this->assertFalse((new Epusdt(['epusdt_token' => 'secret']))->notify([
            'order_id' => 'order-1', 'status' => 2, 'trade_id' => 'tx-1', 'signature' => str_repeat('0', 32),
        ]));

        $this->assertFalse((new EPayQrcode(['pid' => 'merchant-1', 'key' => 'secret']))->notify('not-an-array'));
        $this->assertFalse((new EPayQrcode(['pid' => 'merchant-1', 'key' => 'secret']))->notify([
            'pid' => 'merchant-1', 'out_trade_no' => 'order-1', 'trade_no' => 'tx-1',
            'trade_status' => 'TRADE_SUCCESS', 'sign' => str_repeat('0', 32),
        ]));

        $this->assertFalse((new Paytaro(['pid' => 'app-1', 'key' => 'secret']))->notify('not-an-array'));
        $this->assertFalse((new Paytaro(['pid' => 'app-1', 'key' => 'secret']))->notify([
            'out_trade_no' => 'order-1', 'trade_no' => 'tx-1', 'trade_status' => 'TRADE_SUCCESS', 'sign' => str_repeat('0', 32),
        ]));

        $this->setCallbackSecret('different-secret');
        $this->assertFalse((new PaytaroQR(['pid' => 'app-1', 'key' => 'secret']))->notify([
            'merchant_no' => 'order-1', 'status' => 'PAID', 'callback_no' => 'tx-1',
        ]));
    }

    public function testValidLegacyCallbackSignaturesRemainAccepted()
    {
        $token = 'bepusdt-secret';
        $bepusdtParams = ['order_id' => 'order-1', 'status' => '2', 'trade_id' => 'tx-1'];
        $bepusdtParams['signature'] = $this->beasySignature($bepusdtParams, $token);
        $this->assertSame([
            'trade_no' => 'order-1', 'callback_no' => 'tx-1', 'custom_result' => 'ok',
        ], (new BEasyPaymentUSDT(['bepusdt_apitoken' => $token]))->notify($bepusdtParams));

        $token = 'epusdt-secret';
        $epusdtParams = ['order_id' => 'order-1', 'status' => 2, 'trade_id' => 'tx-1'];
        $epusdtParams['signature'] = strtoupper($this->epusdtSignature($epusdtParams, $token));
        $this->assertSame([
            'trade_no' => 'order-1', 'callback_no' => 'tx-1', 'custom_result' => 'ok',
        ], (new Epusdt(['epusdt_token' => $token]))->notify($epusdtParams));

        $key = 'epay-secret';
        $epayParams = [
            'pid' => 'merchant-1', 'out_trade_no' => 'order-1', 'trade_no' => 'tx-1',
            'trade_status' => 'TRADE_SUCCESS', 'sign_type' => 'MD5',
        ];
        $epayParams['sign'] = $this->epayQrSignature($epayParams, $key);
        $this->assertSame([
            'trade_no' => 'order-1', 'callback_no' => 'tx-1',
        ], (new EPayQrcode(['pid' => 'merchant-1', 'key' => $key]))->notify($epayParams));

        $key = 'paytaro-secret';
        $paytaroParams = [
            'pid' => 'app-1', 'out_trade_no' => 'order-1', 'trade_no' => 'tx-1',
            'trade_status' => 'TRADE_SUCCESS',
        ];
        $paytaroParams['sign'] = $this->sortedMd5Signature($paytaroParams, $key);
        $this->assertSame([
            'trade_no' => 'order-1', 'callback_no' => 'tx-1', 'custom_result' => 'success',
        ], (new Paytaro(['pid' => 'app-1', 'key' => $key]))->notify($paytaroParams));

        $this->setCallbackSecret('paytaro-qr-secret');
        $this->assertSame([
            'trade_no' => 'order-1', 'callback_no' => 'tx-1', 'custom_result' => 'success',
        ], (new PaytaroQR(['pid' => 'app-1', 'key' => 'paytaro-qr-secret']))->notify([
            'merchant_no' => 'order-1', 'status' => 'PAID', 'callback_no' => 'tx-1',
        ]));
    }

    public function testNonSuccessStatusesRemainNonSettlingAcknowledgements()
    {
        $token = 'bepusdt-secret';
        $bepusdtParams = ['order_id' => 'order-1', 'status' => 1, 'trade_id' => 'tx-1'];
        $bepusdtParams['signature'] = $this->beasySignature($bepusdtParams, $token);
        $this->assertSame('failed', (new BEasyPaymentUSDT(['bepusdt_apitoken' => $token]))->notify($bepusdtParams));

        $token = 'epusdt-secret';
        $epusdtParams = ['order_id' => 'order-1', 'status' => 1, 'trade_id' => 'tx-1'];
        $epusdtParams['signature'] = $this->epusdtSignature($epusdtParams, $token);
        $this->assertSame('failed', (new Epusdt(['epusdt_token' => $token]))->notify($epusdtParams));

        $this->setCallbackSecret('paytaro-qr-secret');
        $this->assertFalse((new PaytaroQR(['pid' => 'app-1', 'key' => 'paytaro-qr-secret']))->notify([
            'merchant_no' => 'order-1', 'status' => 'PENDING', 'callback_no' => 'tx-1',
        ]));
    }

    public function testBepusdtAndEpusdtDoNotDisableTlsPeerOrHostVerification()
    {
        foreach (['BEasyPaymentUSDT.php', 'Epusdt.php'] as $file) {
            $root = getenv('P1_OVERRIDE_ROOT') ?: (getenv('P1_SOURCE_ROOT') ?: dirname(__DIR__, 2));
            $source = file_get_contents($root . '/app/Payments/' . $file);
            $this->assertIsString($source);
            $this->assertDoesNotMatchRegularExpression('/CURLOPT_SSL_VERIFYPEER\s*,\s*0/', $source, $file);
            $this->assertDoesNotMatchRegularExpression('/CURLOPT_SSL_VERIFYHOST\s*,\s*0/', $source, $file);
            $this->assertMatchesRegularExpression('/CURLOPT_SSL_VERIFYPEER\s*,\s*true/', $source, $file);
            $this->assertMatchesRegularExpression('/CURLOPT_SSL_VERIFYHOST\s*,\s*2/', $source, $file);
        }
    }

    private function beasySignature(array $params, $token)
    {
        ksort($params);
        return md5(stripslashes(urldecode(http_build_query($params))) . $token);
    }

    private function epusdtSignature(array $params, $token)
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) continue;
            if (is_float($value) || is_int($value)) {
                $value = rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
                $value = $value === '' ? '0' : $value;
            }
            $pairs[] = $key . '=' . (string) $value;
        }
        return strtolower(md5(implode('&', $pairs) . $token));
    }

    private function epayQrSignature(array $params, $key)
    {
        unset($params['sign'], $params['sign_type']);
        $params = array_filter($params, static function ($value) { return $value !== '' && $value !== null; });
        ksort($params);
        $pairs = [];
        foreach ($params as $name => $value) $pairs[] = $name . '=' . $value;
        return strtolower(md5(implode('&', $pairs) . $key));
    }

    private function sortedMd5Signature(array $params, $secret)
    {
        unset($params['sign'], $params['sign_type']);
        $params = array_filter($params, static function ($value) { return $value !== '' && $value !== null; });
        ksort($params);
        $pairs = [];
        foreach ($params as $name => $value) $pairs[] = $name . '=' . $value;
        return md5(implode('&', $pairs) . $secret);
    }

    private function setCallbackSecret($secret)
    {
        $this->serverSecret = array_key_exists('HTTP_X_APP_SECRET', $_SERVER) ? $_SERVER['HTTP_X_APP_SECRET'] : null;
        $_SERVER['HTTP_X_APP_SECRET'] = $secret;
    }
}
