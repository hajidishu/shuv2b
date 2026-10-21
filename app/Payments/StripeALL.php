<?php

namespace App\Payments;
use Stripe\Stripe;
use App\Models\User;
use App\Utils\StripeCurrency;

class StripeALL {
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'currency' => [
                'label' => '货币单位',
                'description' => '请使用符合ISO 4217标准的三位字母，例如GBP',
                'type' => 'input',
            ],
            'stripe_sk_live' => [
                'label' => 'SK_LIVE',
                'description' => '',
                'type' => 'input',
            ],
            'stripe_webhook_key' => [
                'label' => 'WebHook密钥签名',
                'description' => 'whsec_....',
                'type' => 'input',
            ],
            'payment_method' => [
                'label' => '支付方式',
                'description' => '请输入alipay, wechat_pay, cards',
                'type' => 'input',
            ]
        ];
    }

    public function quote($cnyCents): array
    {
        $currency = $this->config['currency'];
        $exchange = $this->exchange('CNY', strtoupper($currency));
        if (!$exchange) abort(500, 'Currency conversion API failed');
        $quote = StripeCurrency::quote(['total_amount' => $cnyCents], $currency, $exchange);
        return ['amount_minor' => $quote['amount'], 'currency' => $quote['currency']];
    }
    
    public function pay($order)
    {
        $currency = $this->config['currency'];
        if (StripeCurrency::hasFrozenQuote($order)) {
            $quote = StripeCurrency::quote($order, $currency, null);
        } else {
            $fetchedQuote = $this->quote($order['total_amount']);
            $quote = ['amount' => $fetchedQuote['amount_minor'], 'currency' => $fetchedQuote['currency']];
        }
        $currency = $quote['currency'];
        //jump url
        $jumpUrl = null;
        $actionType = 0;
        $stripe = new \Stripe\StripeClient($this->config['stripe_sk_live']);
        // 获取用户邮箱
        $userEmail = $this->getUserEmail($order['user_id']);
        if ($this->config['payment_method'] != "cards"){
        $stripePaymentMethod = $stripe->paymentMethods->create([
            'type' => $this->config['payment_method'],
        ], StripeCurrency::idempotencyOptions($order, 'payment-method'));
        // 准备支付意图的基础参数
        $params = [
            'amount' => $quote['amount'],
            'currency' => $currency,
            'confirm' => true,
            'payment_method' => $stripePaymentMethod->id,
            'automatic_payment_methods' => ['enabled' => true],
            'statement_descriptor' => 'user-#' . $order['user_id'] . '-' . substr($order['trade_no'], -8),
            'metadata' => [
                'user_id' => $order['user_id'],
                'customer_email' => $userEmail,
                'out_trade_no' => $order['trade_no']
            ],
            'return_url' => $order['return_url']
        ];

        // 如果支付方式为 wechat_pay，添加相应的支付方式选项
        if ($this->config['payment_method'] === 'wechat_pay') {
            $params['payment_method_options'] = [
                'wechat_pay' => [
                    'client' => 'web'
                ],
            ];
        }
        //更新支持最新的paymentIntents方法，Sources API将在今年被彻底替
        $stripeIntents = $stripe->paymentIntents->create($params, StripeCurrency::idempotencyOptions($order, 'payment-intent'));

        $nextAction = null;
        
        if (!$stripeIntents['next_action']) {
            throw new abort(__('Payment gateway request failed'));
        }else {
            $nextAction = $stripeIntents['next_action'];
        }

        switch ($this->config['payment_method']){
            case "alipay":
                if (isset($nextAction['alipay_handle_redirect'])){
                    $jumpUrl = $nextAction['alipay_handle_redirect']['url'];
                    $actionType = 1;
                }else {
                    throw new abort('unable get Alipay redirect url', 500);
                }
                break;
            case "wechat_pay":
                if (isset($nextAction['wechat_pay_display_qr_code'])){
                    $jumpUrl = $nextAction['wechat_pay_display_qr_code']['data'];
                }else {
                    throw new abort('unable get WeChat Pay redirect url', 500);
                }
        }
    } else {
        $creditCheckOut = $stripe->checkout->sessions->create([
            'success_url' => $order['return_url'],
            'client_reference_id' => $order['trade_no'],
            'payment_method_types' => ['card'],
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => $currency,
                        'unit_amount' => $quote['amount'],
                        'product_data' => [
                            'name' => 'user-#' . $order['user_id'] . '-' . substr($order['trade_no'], -8),
                        ]
                    ],
                    'quantity' => 1,
                ],
            ],
            'mode' => 'payment',
            'invoice_creation' => ['enabled' => true],
            'phone_number_collection' => ['enabled' => false],
            'customer_email' => $userEmail, 
        ], StripeCurrency::idempotencyOptions($order, 'checkout-session'));
        $jumpUrl = $creditCheckOut['url'];
        $actionType = 1;
    }

        return [
            'type' => $actionType,
            'data' => $jumpUrl
        ];
    }

    public function notify($params)
    {
        try {
            $event = \Stripe\Webhook::constructEvent(
                request()->getContent() ?: json_encode($_POST),
                $_SERVER['HTTP_STRIPE_SIGNATURE'],
                $this->config['stripe_webhook_key']
            );
        } catch (\Stripe\Error\SignatureVerification $e) {
            abort(400);
        }
        switch ($event->type) {
            case 'payment_intent.succeeded':
                $object = $event->data->object;
                if ($object->status === 'succeeded') {
                    if (!isset($object->metadata->out_trade_no)) {
                        return('order error');
                    }
                    $metaData = $object->metadata;
                    $tradeNo = $metaData->out_trade_no;
                    return [
                        'trade_no' => $tradeNo,
                        'callback_no' => $object->id,
                        'amount_minor' => (int)(isset($object->amount_received) && $object->amount_received > 0 ? $object->amount_received : $object->amount),
                        'currency' => strtolower((string)$object->currency),
                    ];
                }
                break;
            case 'checkout.session.completed':
                    $object = $event->data->object;
                    if ($object->payment_status === 'paid') {
                        return [
                            'trade_no' => $object->client_reference_id,
                            'callback_no' => $object->payment_intent,
                            'amount_minor' => (int)$object->amount_total,
                            'currency' => strtolower((string)$object->currency),
                        ];
                    }
                    break;
                case 'checkout.session.async_payment_succeeded':
                    $object = $event->data->object;
                    return [
                        'trade_no' => $object->client_reference_id,
                        'callback_no' => $object->payment_intent,
                        'amount_minor' => (int)$object->amount_total,
                        'currency' => strtolower((string)$object->currency),
                    ];
                    break;
            default:
                throw new abort('webhook events are not supported');
        }
        return('success');
    }
    // 货币转换 API
    private function exchange($from, $to)
    {
        try {
            $url = "https://api.exchangerate-api.com/v4/latest/{$from}";
            $result = file_get_contents($url);
            $result = json_decode($result, true);

            // 如果转换成功，返回结果
            if (isset($result['rates'][$to])) {
                return $result['rates'][$to];
            } else {
                throw new \Exception("First currency API fails");
            }
        } catch (\Exception $e) {
            // 如果API失败，调用第二个API
            return $this->backupExchange($from, $to);
        }
    }

    // 第二个货币转换 API 方法
    private function backupExchange($from, $to)
    {
        try {
            $url = "https://api.frankfurter.app/latest?from={$from}&to={$to}";
            $result = file_get_contents($url);
            $result = json_decode($result, true);

            // 如果转换成功，返回结果
            if (isset($result['rates'][$to])) {
                return $result['rates'][$to];
            } else {
                throw new \Exception("Second currency API fails");
            }
        } catch (\Exception $e) {
            // 如果所有API都失败，抛出异常
            throw new \Exception("All currency conversion APIs fail");
        }
    }
    // 从user中获取email
    private function getUserEmail($userId)
    {
        $user = User::find($userId);
        return $user ? $user->email : null;
    }
}
