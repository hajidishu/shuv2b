<?php

require __DIR__ . '/bootstrap.php';

use App\Console\Commands\CheckCommission;
use App\Http\Controllers\V1\User\OrderController;
use App\Http\Requests\User\OrderSave;
use App\Http\Requests\User\UserRedeemGiftCard;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\P1\Environment;

Environment::boot(false);
$operation = $argv[1];
$payload = json_decode($argv[2], true);
$gate = $argv[3];
$workerId = $argv[4];
$barrier = function () use ($gate, $workerId) {
    file_put_contents($gate . '/' . $workerId . '.ready', 'ready');
    $deadline = microtime(true) + 15;
    while (!is_file($gate . '/go')) {
        if (microtime(true) > $deadline) throw new RuntimeException('Worker barrier timed out');
        usleep(10000);
        clearstatcache();
    }
};

try {
    if ($operation === 'commission') {
        // All processes scan the same pending rows before any transaction starts.
        DB::listen(function ($query) use ($barrier) {
            if (strpos($query->sql, 'select') === 0 && strpos($query->sql, 'commission_status') !== false) $barrier();
        });
        (new CheckCommission())->autoPayCommission();
        $result = true;
    } elseif ($operation === 'auto-renew') {
        $barrier();
        (new \App\Console\Commands\CheckRenewal())->handle();
        $result = true;
    } elseif ($operation === 'save') {
        $request = new OrderSave();
        $request->merge(['user' => ['id' => $payload['user_id']], 'plan_id' => $payload['plan_id'], 'period' => 'month_price']);
        $barrier();
        (new OrderController())->save($request);
        $result = true;
    } elseif ($operation === 'redeem') {
        $request = new UserRedeemGiftCard();
        $request->merge(['user' => ['id' => $payload['user_id']], 'giftcard' => $payload['code']]);
        $barrier();
        (new \App\Http\Controllers\V1\User\UserController())->redeemgiftcard($request);
        $result = true;
    } elseif ($operation === 'epay') {
        $params = ['pid' => 'merchant-1', 'trade_status' => 'TRADE_SUCCESS', 'out_trade_no' => $payload['trade_no'], 'trade_no' => $payload['receipt'], 'money' => '100.00'];
        ksort($params);
        $params['sign'] = md5(stripslashes(urldecode(http_build_query($params))) . 'secret');
        $params['sign_type'] = 'MD5';
        $request = \Illuminate\Http\Request::create('/', 'POST', $params);
        Environment::$app->instance('request', $request);
        $barrier();
        (new \App\Http\Controllers\V1\Guest\PaymentController())->notify('EPay', $payload['gateway'], $request);
        $result = true;
    } else {
        // Retain a real stale Eloquent snapshot, as a queued request/worker can.
        $service = new OrderService(Order::findOrFail($payload['order_id']));
        $barrier();
        if ($operation === 'open') { $service->open(); $result = true; }
        elseif ($operation === 'paid') $result = $service->paid('parallel-payment');
        elseif ($operation === 'cancel') $result = $service->cancel();
        else throw new RuntimeException('Unknown worker operation');
    }
    echo json_encode(['result' => $result]), PHP_EOL;
} catch (HttpException $e) {
    if (!in_array($operation, ['save', 'redeem', 'epay'], true)) throw $e;
    echo json_encode(['result' => false, 'reason' => $e->getMessage()]), PHP_EOL;
}
