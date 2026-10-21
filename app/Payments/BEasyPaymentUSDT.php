<?php

namespace App\Payments;

use \Curl\Curl;

class BEasyPaymentUSDT {
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'bepusdt_url' => [
                'label' => 'API 地址',
                'description' => '您的 BEPUSDT API 接口地址(例如: https://xxx.com)',
                'type' => 'input',
            ],
            'bepusdt_apitoken' => [
                'label' => 'API Token',
                'description' => '您的 BEPUSDT API Token',
                'type' => 'input',
            ],
            'bepusdt_trade_type' => [
                'label' => '交易类型',
                'description' => '您的 BEPUSDT 交易类型',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        $apiToken = $this->apiToken();
        if ($apiToken === null) {
            abort(500, 'BEPUSDT API token is required');
        }
        $params = [
            'amount' => $order['total_amount'] / 100,
            'trade_type' => $this->config['bepusdt_trade_type'],
            'notify_url' => $order['notify_url'],
            'order_id' => $order['trade_no'],
            'redirect_url' => $order['return_url']
        ];
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $apiToken;
        $params['signature'] = md5($str);

        $curl = new Curl();
        $curl->setUserAgent('BEPUSDT');
        $curl->setOpt(CURLOPT_SSL_VERIFYPEER, true);
        $curl->setOpt(CURLOPT_SSL_VERIFYHOST, 2);
        $curl->setOpt(CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        $curl->post($this->config['bepusdt_url'] . '/api/v1/order/create-transaction', json_encode($params));
        $result = $curl->response;
        $curl->close();

        if (!isset($result->status_code) || $result->status_code != 200) {
            abort(500, "Failed to create order. Error: {$result->message}");
        }

        $paymentURL = $result->data->payment_url;
        return [
            'type' => 1, // 0:qrcode 1:url
            'data' => $paymentURL
        ];
    }

    public function notify($params)
    {
        $apiToken = $this->apiToken();
        if ($apiToken === null || !$this->hasScalarValues($params) ||
            !isset($params['signature']) || !is_string($params['signature']) ||
            !preg_match('/\A[0-9a-fA-F]{32}\z/', $params['signature'])) {
            return false;
        }

        $sign = strtolower($params['signature']);
        unset($params['signature']);
        ksort($params);
        reset($params);
        $str = stripslashes(urldecode(http_build_query($params))) . $apiToken;
        $generateSignature = md5($str);
        if (!hash_equals($generateSignature, $sign)) {
            return false;
        }
        $status = $params['status'] ?? null;
        if ((!is_string($status) && !is_int($status)) || !preg_match('/\A[0-9]+\z/', (string) $status)) {
            return false;
        }
        // 1: pending 2: success 3: expired
        if ((string) $status !== '2') {
            return('failed');
        }
        if (!$this->hasIdentifier($params['order_id'] ?? null) || !$this->hasIdentifier($params['trade_id'] ?? null)) {
            return false;
        }
        return [
            'trade_no' => (string) $params['order_id'],
            'callback_no' => (string) $params['trade_id'],
            'custom_result' => 'ok'
        ];
    }

    private function apiToken()
    {
        $token = $this->config['bepusdt_apitoken'] ?? null;
        if (!is_string($token) && !is_int($token)) return null;
        if (trim((string) $token) === '') return null;
        return (string) $token;
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
