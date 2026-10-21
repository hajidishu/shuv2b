<?php
/**
 * Paytaro 支付接口（V2Board / Xboard）
 * 预置。后台「支付配置」添加时接口选择 Paytaro；
 * 文档：https://v3.paytaro.com/#/docs/v2board
 */

namespace App\Payments;

class Paytaro
{
    const GATEWAY_URL = 'https://v3.paytaro.com';

    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'pid' => [
                'label' => 'App ID',
                'description' => 'Paytaro 应用的 App ID；',
                'type' => 'input',
            ],
            'key' => [
                'label' => 'App Secret',
                'description' => 'Paytaro 应用的 App Secret；',
                'type' => 'input',
            ],
        ];
    }

    private function appId()
    {
        $appId = $this->config['pid'] ?? null;
        return (is_string($appId) || is_int($appId)) && trim((string) $appId) !== '' ? trim((string) $appId) : '';
    }

    private function appSecret()
    {
        $secret = $this->config['key'] ?? null;
        return (is_string($secret) || is_int($secret)) && trim((string) $secret) !== '' ? trim((string) $secret) : '';
    }

    private function sign(array $params)
    {
        unset($params['sign'], $params['sign_type']);
        $params = array_filter($params, function ($v) { return $v !== '' && $v !== null; });
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        return md5(implode('&', $pairs) . $this->appSecret());
    }

    public function pay($order)
    {
        if ($this->appId() === '' || $this->appSecret() === '') {
            abort(500, 'Paytaro app ID and secret are required');
        }
        $params = [
            'pid' => $this->appId(),
            'type' => 'alipay',
            'out_trade_no' => $order['trade_no'],
            'notify_url' => $order['notify_url'],
            'return_url' => $order['return_url'],
            'name' => $order['trade_no'],
            'money' => number_format($order['total_amount'] / 100, 2, '.', ''),
        ];
        $params = array_filter($params, function ($v) { return $v !== '' && $v !== null; });
        $params['sign'] = $this->sign($params);
        $params['sign_type'] = 'MD5';

        return [
            'type' => 1, // 0: qrcode, 1: url
            'data' => self::GATEWAY_URL . '/submit.php?' . http_build_query($params),
        ];
    }

    public function notify($params)
    {
        if ($this->appId() === '' || $this->appSecret() === '' || !$this->hasScalarValues($params) ||
            !isset($params['sign']) || !is_string($params['sign']) ||
            !preg_match('/\A[0-9a-fA-F]{32}\z/', $params['sign'])) {
            return false;
        }
        if (isset($params['sign_type']) && (!is_string($params['sign_type']) || strtoupper($params['sign_type']) !== 'MD5')) {
            return false;
        }
        if (!hash_equals($this->sign($params), strtolower($params['sign']))) return false;
        if (($params['trade_status'] ?? '') !== 'TRADE_SUCCESS') {
            return false;
        }
        if (!$this->hasIdentifier($params['out_trade_no'] ?? null) || !$this->hasIdentifier($params['trade_no'] ?? null)) {
            return false;
        }
        if (isset($params['pid']) && (!$this->hasIdentifier($params['pid']) || (string) $params['pid'] !== $this->appId())) {
            return false;
        }
        return [
            'trade_no' => (string) $params['out_trade_no'],
            'callback_no' => (string) $params['trade_no'],
            'custom_result' => 'success',
        ];
    }

    private function hasScalarValues($params)
    {
        if (!is_array($params)) return false;
        foreach ($params as $value) {
            if (!is_scalar($value) && $value !== null) return false;
        }
        return true;
    }

    private function hasIdentifier($value)
    {
        return (is_string($value) || is_int($value)) && trim((string) $value) !== '';
    }
}
