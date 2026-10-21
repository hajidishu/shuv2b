<?php

namespace App\Services;


use App\Models\Payment;

class PaymentService
{
    public $method;
    protected $class;
    protected $config;
    protected $payment;

    public function __construct($method, $id = NULL, $uuid = NULL)
    {
        $this->method = $method;
        $this->class = '\\App\\Payments\\' . $this->method;
        if (!class_exists($this->class)) abort(500, 'gate is not found');
        if ($id || $uuid) {
            $payment = $id ? Payment::find($id) : Payment::where('uuid', $uuid)->first();
            if (!$payment || $payment->payment !== $method) abort(500, 'gate is not found');
            $payment = $payment->toArray();
        }
        $this->config = [];
        if (isset($payment)) {
            $this->config = $payment['config'];
            $this->config['enable'] = $payment['enable'];
            $this->config['id'] = $payment['id'];
            $this->config['uuid'] = $payment['uuid'];
            $this->config['notify_domain'] = $payment['notify_domain'];
        };
        $this->payment = new $this->class($this->config);
    }

    public function notify($params)
    {
        $result = $this->payment->notify($params);
        if (is_array($result)) {
            $result['payment_id'] = $this->config['id'];
            if (!$this->config['enable']) {
                $attempt = PaymentLedgerSchema::installed() ? \Illuminate\Support\Facades\DB::table(PaymentAttemptService::TABLE)
                    ->where('trade_no', $result['trade_no'] ?? '')->where('payment_id', $this->config['id'])->first() : null;
                if (!$attempt) abort(500, 'gate is not enable');
                $result['verified_attempt_id'] = $attempt->id;
            }
        } elseif (!$this->config['enable']) abort(500, 'gate is not enable');
        return $result;
    }

    public static function notifyRequest($method, $uuid, $request)
    {
        if (PaymentLedgerSchema::installed()) {
            // An untrusted reference selects a verification key, never authorizes settlement.
            $json = json_decode($request->getContent(), true);
            $object = is_array($json) ? ($json['data']['object'] ?? []) : [];
            if (!is_array($object)) $object = [];
            $reference = $request->input('out_trade_no') ?? $request->input('order_id') ?? $request->input('merchant_no') ??
                ($object['client_reference_id'] ?? $object['metadata']['out_trade_no'] ?? $json['event']['data']['metadata']['outTradeNo'] ?? null);
            if (is_string($reference)) {
                $attempt = \Illuminate\Support\Facades\DB::table(PaymentAttemptService::TABLE)->where('trade_no', $reference)->first();
                if ($attempt && $attempt->method === $method && $attempt->gateway_uuid === $uuid && $attempt->config_snapshot) {
                    try { $result = self::verifySnapshot($attempt, $request); if ($result) return $result; }
                    catch (\Throwable $ignored) { /* Providers may sign old attempts using their newly rotated key. */ }
                }
            }
        }
        $failure = null;
        try {
            $service = new self($method, null, $uuid);
            $result = $service->notify($request->input());
            if ($result) return $result;
        } catch (\Throwable $error) { $failure = $error; }
        if (PaymentLedgerSchema::installed()) {
            // Some providers (e.g. BTCPay) omit the merchant reference from their event.
            // Try distinct historical configurations, then bind the verified response back
            // to an attempt created with exactly that configuration before settling money.
            $ids = \Illuminate\Support\Facades\DB::table(PaymentAttemptService::TABLE)->where('gateway_uuid', $uuid)->where('method', $method)
                ->whereNotNull('config_snapshot')->selectRaw('MAX(id) AS id')->groupBy('config_hash')->get();
            foreach ($ids as $id) {
                $candidate = \Illuminate\Support\Facades\DB::table(PaymentAttemptService::TABLE)->where('id', $id->id)->first();
                try { $result = self::verifySnapshot($candidate, $request); if ($result) return $result; }
                catch (\Throwable $error) { $failure = $error; }
            }
        }
        if ($failure) throw $failure;
        return false;
    }

    private static function verifySnapshot($candidate, $request)
    {
        $snapshot = PaymentConfigSnapshot::open($candidate->config_snapshot);
        $config = $snapshot['config'];
        $config['id'] = $candidate->payment_id; $config['uuid'] = $candidate->gateway_uuid; $config['notify_domain'] = $snapshot['notify_domain'];
        $adapterClass = '\\App\\Payments\\' . $candidate->method;
        $result = (new $adapterClass($config))->notify($request->input());
        if (!is_array($result)) return $result;
        $target = \Illuminate\Support\Facades\DB::table(PaymentAttemptService::TABLE)->where('trade_no', $result['trade_no'] ?? '')->first();
        if (!$target || (int)$target->payment_id !== (int)$candidate->payment_id || $target->config_hash !== $candidate->config_hash ||
            $target->method !== $candidate->method || $target->gateway_uuid !== $candidate->gateway_uuid) return false;
        $result['payment_id'] = $target->payment_id; $result['verified_attempt_id'] = $target->id;
        return $result;
    }

    public function pay($order)
    {
        // custom notify domain name
        $notifyUrl = url("/api/v1/guest/payment/notify/{$this->method}/{$this->config['uuid']}");
        if ($this->config['notify_domain']) {
            $parseUrl = parse_url($notifyUrl);
            $notifyUrl = $this->config['notify_domain'] . $parseUrl['path'];
        }
        $returnTradeNo = $order['return_trade_no'] ?? $order['trade_no'];
        $returnUrl = PaymentReturnUrl::make($returnTradeNo);
        $params = [
            'notify_url' => $notifyUrl,
            'return_url' => $returnUrl,
            'return_home_url' => PaymentReturnUrl::base() . '/',
            'trade_no' => $order['trade_no'],
            'total_amount' => $order['total_amount'],
            'user_id' => $order['user_id'],
            'stripe_token' => $order['stripe_token']
        ];
        foreach (['gateway_amount_minor', 'gateway_currency', 'idempotency_key'] as $key) {
            if (array_key_exists($key, $order)) $params[$key] = $order[$key];
        }
        return $this->payment->pay($params);
    }

    public function quote($amount)
    {
        if (!method_exists($this->payment, 'quote')) abort(422, '支付网关不支持冻结报价');
        return $this->payment->quote($amount);
    }

    public function form()
    {
        $form = $this->payment->form();
        $keys = array_keys($form);
        foreach ($keys as $key) {
            if (isset($this->config[$key])) $form[$key]['value'] = $this->config[$key];
        }
        return $form;
    }

    public static function validateConfiguration($method, array $config)
    {
        $required = ['BEasyPaymentUSDT' => ['bepusdt_apitoken'], 'Epusdt' => ['epusdt_token'],
            'EPay' => ['key'], 'EPayQrcode' => ['key'], 'Paytaro' => ['key'], 'PaytaroQR' => ['key']];
        foreach ($required[$method] ?? [] as $key) {
            if ((!is_string($config[$key] ?? null) && !is_int($config[$key] ?? null)) || trim((string)$config[$key]) === '') abort(422, '支付配置缺少有效密钥：' . $key);
        }
    }

}
