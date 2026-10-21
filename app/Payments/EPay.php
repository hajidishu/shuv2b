<?php

namespace App\Payments;

use App\Utils\Money;

class EPay {
    private $config;
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'url' => [
                'label' => 'URL',
                'description' => '',
                'type' => 'input',
            ],
            'pid' => [
                'label' => 'PID',
                'description' => '',
                'type' => 'input',
            ],
            'key' => [
                'label' => 'KEY',
                'description' => '',
                'type' => 'input',
            ],
            'type' => [
                'label' => 'TYPE',
                'description' => '支付类型，如: alipay, wxpay, qqpay',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        $params = [
            'money' => sprintf('%.2f', $order['total_amount'] / 100),
            'name' => $order['trade_no'],
            'notify_url' => $order['notify_url'],
            'return_url' => $order['return_url'],
            'out_trade_no' => $order['trade_no'],
            'pid' => trim((string)$this->config['pid'])
        ];
        if (!empty($this->config['type'])) {
            $params['type'] = $this->config['type'];
        }
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->config['key'];
        $params['sign'] = md5($str);
        $params['sign_type'] = 'MD5';
        return [
            'type' => 1, // 0:qrcode 1:url
            'data' => $this->config['url'] . '/submit.php?' . http_build_query($params)
        ];
    }

    public function notify($params)
    {
        if (!is_array($params) || !is_string($params['sign'] ?? null) ||
            !preg_match('/\A[0-9a-fA-F]{32}\z/', $params['sign']) ||
            !is_string($this->config['key'] ?? null) || trim($this->config['key']) === '') return false;
        if (!is_string($params['sign_type'] ?? 'MD5') || strtoupper($params['sign_type'] ?? 'MD5') !== 'MD5') return false;
        foreach ($params as $value) {
            if (!is_scalar($value) && $value !== null) return false;
        }
        $sign = strtolower($params['sign']);
        unset($params['sign']);
        unset($params['sign_type']);
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $this->config['key'];
        $generateSignature = md5($str);
        if (!hash_equals($generateSignature, $sign)) {
            return false;
        }
        $configuredPid = $this->config['pid'] ?? null;
        if ((!is_string($configuredPid) && !is_int($configuredPid)) ||
            !isset($params['pid']) || (string)$params['pid'] !== trim((string)$configuredPid)) return false;

        // 强制要求交易状态为成功，避免未支付/处理中状态被误入账
        $tradeStatus = $params['trade_status'] ?? '';
        if ($tradeStatus !== 'TRADE_SUCCESS') {
            return('fail');
        }
        $money = $params['money'] ?? null;
        if ((!is_string($money) && !is_int($money) && !is_float($money)) ||
            !preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/', (string)$money)) return false;
        $cents = Money::toCents($money);
        if ($cents === null || $cents <= 0) return false;
        foreach (['out_trade_no', 'trade_no'] as $field) {
            if (!isset($params[$field]) || !is_scalar($params[$field]) || trim((string)$params[$field]) === '') return false;
        }
        if (isset($params['currency']) && strtoupper((string)$params['currency']) !== 'CNY') return false;

        return [
            'trade_no' => (string)$params['out_trade_no'],
            'callback_no' => (string)$params['trade_no'],
            'amount' => $money,
            'currency' => 'CNY',
            'expected_currency' => 'CNY'
        ];
    }
}
