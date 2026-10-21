<?php

namespace Tests\P1;

use App\Console\Commands\CheckCommission;
use App\Http\Controllers\V1\User\OrderController;
use App\Models\CommissionLog;
use App\Models\Giftcard;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;

class ConcurrencyTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        if (getenv('P1_DB_DRIVER') !== 'mysql') $this->markTestSkipped('Set P1_DB_DRIVER=mysql and use an isolated v2board_p1_* InnoDB database.');
        parent::setUp();
    }

    private function parallel(array $operations)
    {
        $gate = sys_get_temp_dir() . '/v2board-p1-' . bin2hex(random_bytes(8));
        mkdir($gate);
        $processes = [];
        $phpArgs = json_decode(getenv('P1_WORKER_PHP_ARGS') ?: '[]', true);
        try {
            foreach ($operations as $index => $operation) {
                $args = array_merge([PHP_BINARY], $phpArgs, [__DIR__ . '/worker.php', $operation[0], json_encode($operation[1]), $gate, (string)$index]);
                $process = new Process($args, dirname(__DIR__, 2));
                $process->setTimeout(25);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while (count(glob($gate . '/*.ready')) !== count($processes)) {
                foreach ($processes as $process) {
                    if (!$process->isRunning()) $this->fail('Worker exited before barrier: ' . $process->getOutput() . $process->getErrorOutput());
                }
                if (microtime(true) > $deadline) $this->fail('Parallel test barrier timed out');
                usleep(10000);
            }
            file_put_contents($gate . '/go', 'go');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
                $result = json_decode(trim($process->getOutput()), true);
                $this->assertIsArray($result, $process->getOutput());
                $results[] = $result;
            }
            return $results;
        } finally {
            foreach ($processes as $process) if ($process->isRunning()) $process->stop();
            foreach (glob($gate . '/*') as $file) unlink($file);
            rmdir($gate);
        }
    }

    public function testTenWorkersCreditSamePaidOrderOnlyOnce()
    {
        $user = $this->user();
        $order = $this->order($user, ['status' => 1]);
        $this->parallel(array_fill(0, 10, ['open', ['order_id' => $order->id]]));
        $this->assertSame(10000, (int)$user->fresh()->balance);
        $this->assertSame(3, (int)$order->fresh()->status);
    }

    public function testDifferentDepositsForSameUserDoNotLoseCredits()
    {
        $user = $this->user();
        $operations = [];
        for ($i = 0; $i < 8; $i++) {
            $order = $this->order($user, ['status' => 1]);
            $operations[] = ['open', ['order_id' => $order->id]];
        }
        $this->parallel($operations);
        $this->assertSame(80000, (int)$user->fresh()->balance);
        $this->assertSame(8, Order::where('status', 3)->count());
    }

    public function testParallelPaymentAndCancellationHaveOneFinancialOutcome()
    {
        $plan = Plan::create(['month_price' => 10000]);
        $user = $this->user();
        $order = $this->order($user, ['total_amount' => 0, 'balance_amount' => 10000, 'type' => 1, 'plan_id' => $plan->id, 'period' => 'month_price']);
        $operations = array_merge(array_fill(0, 4, ['paid', ['order_id' => $order->id]]), array_fill(0, 4, ['cancel', ['order_id' => $order->id]]));
        $this->parallel($operations);
        $status = (int)$order->fresh()->status;
        $this->assertContains($status, [1, 2]);
        if ($status === 1) {
            (new OrderService($order))->open();
            $this->assertSame(0, (int)$user->fresh()->balance);
            $this->assertSame($plan->id, $user->fresh()->plan_id);
        } else {
            $this->assertSame(10000, (int)$user->fresh()->balance);
            $this->assertNull($user->fresh()->plan_id);
        }
    }

    public function testEightPlanChangeRequestsCannotDuplicateSourceRefund()
    {
        $old = Plan::create(['onetime_price' => 20000]);
        $target = Plan::create(['month_price' => 5000]);
        $user = $this->user(['plan_id' => $old->id, 'expired_at' => null, 'transfer_enable' => 107374182400]);
        $source = $this->order($user, ['status' => 3, 'type' => 1, 'plan_id' => $old->id, 'period' => 'onetime_price', 'total_amount' => 20000]);
        $results = $this->parallel(array_fill(0, 8, ['save', ['user_id' => $user->id, 'plan_id' => $target->id]]));
        $this->assertSame(1, count(array_filter($results, function ($result) { return $result['result']; })));
        $order = Order::where('status', 0)->firstOrFail();
        $this->assertSame(15000, (int)$order->refund_amount);
        $request = new Request(['user' => ['id' => $user->id], 'trade_no' => $order->trade_no]);
        (new OrderController())->checkout($request);
        (new OrderService($order))->open();
        $this->assertSame(15000, (int)$user->fresh()->balance);
        $this->assertSame(4, (int)$source->fresh()->status);
    }

    public function testEightStaleCommissionScansPayEachOrderOnce()
    {
        $inviter = $this->user();
        $buyer = $this->user();
        foreach ([1000, 2000, 3000] as $commission) {
            $this->order($buyer, ['status' => 3, 'commission_status' => 1, 'invite_user_id' => $inviter->id, 'commission_balance' => $commission]);
        }
        $this->parallel(array_fill(0, 8, ['commission', []]));
        $this->assertSame(6000, (int)$inviter->fresh()->commission_balance);
        $this->assertSame(3, CommissionLog::count());
        $this->assertSame(3, Order::where('commission_status', 2)->count());
    }

    public function testTenConcurrentCancellationsRefundOnlyOnce()
    {
        $user = $this->user();
        $order = $this->order($user, ['total_amount' => 9900, 'balance_amount' => 100]);
        $results = $this->parallel(array_fill(0, 10, ['cancel', ['order_id' => $order->id]]));
        $this->assertSame(1, count(array_filter($results, function ($result) { return $result['result']; })));
        $this->assertSame(100, (int)$user->fresh()->balance);
        $this->assertSame(2, (int)$order->fresh()->status);
    }

    public function testDifferentUsersCannotOverclaimLastGiftcardUse()
    {
        $card = Giftcard::create(['code' => 'last-use', 'type' => 1, 'value' => 1000, 'limit_use' => 1]);
        $operations = [];
        for ($i = 0; $i < 8; $i++) {
            $user = $this->user();
            $operations[] = ['redeem', ['user_id' => $user->id, 'code' => $card->code]];
        }
        $results = $this->parallel($operations);
        $this->assertSame(1, count(array_filter($results, function ($result) { return $result['result']; })));
        $this->assertSame(1000, (int)User::sum('balance'));
        $this->assertSame(0, (int)$card->fresh()->limit_use);
        $this->assertCount(1, $card->fresh()->used_user_ids);
    }

    public function testSameUserCannotRedeemUnlimitedCardMoreThanOnce()
    {
        $user = $this->user();
        $card = Giftcard::create(['code' => 'one-per-user', 'type' => 1, 'value' => 1000, 'limit_use' => null]);
        $results = $this->parallel(array_fill(0, 8, ['redeem', ['user_id' => $user->id, 'code' => $card->code]]));
        $this->assertSame(1, count(array_filter($results, function ($result) { return $result['result']; })));
        $this->assertSame(1000, (int)$user->fresh()->balance);
    }

    public function testDifferentCardsForSameUserAccumulateWithoutLostCredits()
    {
        $user = $this->user();
        $operations = [];
        for ($i = 0; $i < 6; $i++) {
            $card = Giftcard::create(['code' => 'card-' . $i, 'type' => 1, 'value' => 1000, 'limit_use' => 1]);
            $operations[] = ['redeem', ['user_id' => $user->id, 'code' => $card->code]];
        }
        $this->parallel($operations);
        $this->assertSame(6000, (int)$user->fresh()->balance);
        $this->assertSame(6, Giftcard::where('limit_use', 0)->count());
    }

    public function testGiftcardCannotOverwriteConcurrentOrderDebit()
    {
        $user = $this->user(['balance' => 50000]);
        $plan = Plan::create(['month_price' => 50000]);
        $card = Giftcard::create(['code' => 'debit-race', 'type' => 1, 'value' => 10000, 'limit_use' => 1]);
        $gate = sys_get_temp_dir() . '/v2board-gift-debit-' . bin2hex(random_bytes(8));
        mkdir($gate);
        $ran = false; $process = null;
        User::retrieved(function ($snapshot) use (&$ran, &$process, $user, $plan, $gate) {
            if ($ran || $snapshot->id !== $user->id) return;
            $ran = true;
            $phpArgs = json_decode(getenv('P1_WORKER_PHP_ARGS') ?: '[]', true);
            $process = new Process(array_merge([PHP_BINARY], $phpArgs, [__DIR__ . '/GiftcardDebitWorker.php', (string)$user->id, (string)$plan->id, $gate]));
            $process->setTimeout(10); $process->start();
            $deadline = microtime(true) + 5;
            while (!is_file($gate . '/started')) {
                if (!$process->isRunning()) throw new \RuntimeException($process->getOutput() . $process->getErrorOutput());
                if (microtime(true) > $deadline) throw new \RuntimeException('Debit worker did not start');
                usleep(10000); clearstatcache();
            }
            // An unlocked debit can finish here; a locked debit waits for gift-card commit.
            $deadline = microtime(true) + 0.5;
            while ($process->isRunning() && microtime(true) < $deadline) usleep(10000);
        });
        try {
            $r = new \App\Http\Requests\User\UserRedeemGiftCard();
            $r->merge(['user' => ['id' => $user->id], 'giftcard' => $card->code]);
            (new \App\Http\Controllers\V1\User\UserController())->redeemgiftcard($r);
            $this->assertNotNull($process);
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
            $order = Order::where('user_id', $user->id)->firstOrFail();
            $this->assertSame(10000, (int)$user->fresh()->balance);
            $this->assertSame(50000, (int)$order->balance_amount);
            (new OrderController())->checkout(new Request(['user' => ['id' => $user->id], 'trade_no' => $order->trade_no]));
            (new OrderService($order))->open();
            $this->assertSame(10000, (int)$user->fresh()->balance);
            $this->assertSame($plan->id, $user->fresh()->plan_id);
        } finally {
            if ($process && $process->isRunning()) $process->stop();
            foreach (glob($gate . '/*') as $file) unlink($file);
            rmdir($gate);
        }
    }

    public function testSameEpayReceiptCannotSettleTwoUsersOrdersConcurrently()
    {
        $payment = Payment::create(['uuid' => 'shared-gateway', 'payment' => 'EPay', 'config' => ['pid' => 'merchant-1', 'key' => 'secret']]);
        $first = $this->order($this->user(), ['payment_id' => $payment->id]);
        $second = $this->order($this->user(), ['payment_id' => $payment->id]);
        $operations = [];
        foreach ([$first, $second] as $order) $operations[] = ['epay', ['trade_no' => $order->trade_no, 'receipt' => 'same-receipt', 'gateway' => $payment->uuid]];
        $results = $this->parallel($operations);
        $this->assertSame(1, count(array_filter($results, function ($result) { return $result['result']; })));
        $this->assertSame(1, Order::where('status', 1)->count());
        foreach ([$first, $second] as $order) (new OrderService($order))->open();
        $this->assertSame(10000, (int)User::sum('balance'));
    }

    public function testConcurrentLateReceiptReplaysCreditWalletOnlyOnce()
    {
        $payment = Payment::create(['uuid' => 'late-shared', 'payment' => 'EPay', 'config' => ['pid' => 'merchant-1', 'key' => 'secret']]);
        $user = $this->user(); $order = $this->order($user, ['payment_id' => $payment->id]);
        (new OrderService($order))->cancel();
        $results = $this->parallel(array_fill(0, 10, ['epay', ['trade_no' => $order->trade_no, 'receipt' => 'same-late-receipt', 'gateway' => $payment->uuid]]));
        $this->assertSame(10, count(array_filter($results, function ($item) { return $item['result']; })));
        $this->assertSame(10000, (int)$user->fresh()->balance);
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('v2_payment_receipt')->count());
        $this->assertSame(2, (int)$order->fresh()->status);
    }
    public function testExternalReceiptAndCouponCancellationHaveOneConsistentOutcome()
    {
        $payment = Payment::create(['uuid' => 'cancel-receipt', 'payment' => 'EPay', 'config' => ['pid' => 'merchant-1', 'key' => 'secret']]);
        $coupon = \App\Models\Coupon::create(['code' => 'reserved', 'value' => 1000, 'type' => 1, 'limit_use' => 0, 'started_at' => time() - 1, 'ended_at' => time() + 100]);
        $user = $this->user(); $plan = Plan::create(['month_price' => 12500]);
        $order = $this->order($user, ['payment_id' => $payment->id, 'balance_amount' => 2500, 'coupon_id' => $coupon->id,
            'plan_id' => $plan->id, 'period' => 'month_price', 'type' => 1]);
        $operations = array_merge(array_fill(0, 4, ['cancel', ['order_id' => $order->id]]),
            array_fill(0, 4, ['epay', ['trade_no' => $order->trade_no, 'receipt' => 'one-real-receipt', 'gateway' => $payment->uuid]]));
        $this->parallel($operations);
        if ((int)$order->fresh()->status === 1) {
            (new OrderService($order))->open();
            $this->assertSame(0, (int)$user->fresh()->balance); $this->assertSame(0, (int)$coupon->fresh()->limit_use);
            $this->assertSame((int)$plan->id, (int)$user->fresh()->plan_id);
        } else {
            $this->assertSame(2, (int)$order->fresh()->status); $this->assertSame(12500, (int)$user->fresh()->balance);
            $this->assertSame(1, (int)$coupon->fresh()->limit_use); $this->assertNull($user->fresh()->plan_id);
        }
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('v2_payment_receipt')->count());
    }
    public function testConcurrentAutoRenewalsCannotDoubleDebit()
    {
        $plan = Plan::create(['month_price' => 10000]);
        $user = $this->user(['plan_id' => $plan->id, 'auto_renewal' => 1, 'expired_at' => time() + 86400, 'balance' => 20000]);
        $this->order($user, ['plan_id' => $plan->id, 'period' => 'month_price', 'type' => 1, 'status' => 3]);
        $this->parallel(array_fill(0, 6, ['auto-renew', []]));
        $this->assertSame(10000, (int)$user->fresh()->balance);
        $this->assertSame(2, Order::where('user_id', $user->id)->count());
    }

    public function testAutoRenewalCannotOverwriteConcurrentOrderDebit()
    {
        $plan = Plan::create(['month_price' => 10000, 'reset_price' => 50000]);
        $user = $this->user(['plan_id' => $plan->id, 'auto_renewal' => 1, 'expired_at' => time() + 86400, 'balance' => 60000, 'transfer_enable' => 107374182400]);
        $this->order($user, ['plan_id' => $plan->id, 'period' => 'month_price', 'type' => 1, 'status' => 3]);
        $gate = sys_get_temp_dir() . '/v2board-renew-debit-' . bin2hex(random_bytes(8));
        mkdir($gate);
        $ran = false; $process = null;
        User::retrieved(function ($snapshot) use (&$ran, &$process, $user, $plan, $gate) {
            if ($ran || $snapshot->id !== $user->id) return;
            $ran = true;
            $phpArgs = json_decode(getenv('P1_WORKER_PHP_ARGS') ?: '[]', true);
            $process = new Process(array_merge([PHP_BINARY], $phpArgs, [__DIR__ . '/GiftcardDebitWorker.php', (string)$user->id, (string)$plan->id, $gate, 'reset_price']));
            $process->setTimeout(10); $process->start();
            $deadline = microtime(true) + 5;
            while (!is_file($gate . '/started')) {
                if (!$process->isRunning()) throw new \RuntimeException($process->getOutput() . $process->getErrorOutput());
                if (microtime(true) > $deadline) throw new \RuntimeException('Debit worker did not start');
                usleep(10000); clearstatcache();
            }
            $deadline = microtime(true) + 0.5;
            while ($process->isRunning() && microtime(true) < $deadline) usleep(10000);
        });
        try {
            (new \App\Console\Commands\CheckRenewal())->handle();
            $this->assertNotNull($process);
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
            $this->assertSame(0, (int)$user->fresh()->balance);
            $this->assertSame(3, Order::where('user_id', $user->id)->count());
            $this->assertSame(50000, (int)Order::where('period', 'reset_price')->firstOrFail()->balance_amount);
        } finally {
            if ($process && $process->isRunning()) $process->stop();
            foreach (glob($gate . '/*') as $file) unlink($file);
            rmdir($gate);
        }
    }
}
