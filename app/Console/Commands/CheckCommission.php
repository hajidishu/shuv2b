<?php

namespace App\Console\Commands;

use App\Models\CommissionLog;
use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckCommission extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:commission';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '返佣服务';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->autoCheck();
        $this->autoPayCommission();
    }

    public function autoCheck()
    {
        Order::where('period', 'deposit')->where('total_amount', 0)
            ->whereIn('status', [3, 4])->whereIn('commission_status', [0, 1])
            ->update(['commission_status' => 3]);
        if ((int)config('v2board.commission_auto_check_enable', 1)) {
            Order::where('commission_status', 0)
                ->where('invite_user_id', '!=', NULL)
                ->whereIn('status', [3, 4])
                ->where('updated_at', '<=', strtotime('-3 day', time()))
                ->update([
                    'commission_status' => 1
                ]);
        }
    }

    public function autoPayCommission()
    {
        $orders = Order::where('commission_status', 1)
            ->where('invite_user_id', '!=', NULL)
            ->get();
        foreach ($orders as $order) {
            try {
                DB::transaction(function () use ($order) {
                    $order = Order::where('id', $order->id)->lockForUpdate()->first();
                    if (!$order || (int)$order->commission_status !== 1 ||
                        !in_array((int)$order->status, [3, 4], true)) return;
                    if ($order->period === 'deposit' && (int)$order->total_amount === 0) {
                        $order->commission_status = 3;
                        if (!$order->save()) throw new \RuntimeException('返佣状态保存失败');
                        return;
                    }
                    if (!$this->payHandle($order->invite_user_id, $order)) {
                        throw new \RuntimeException('返佣失败');
                    }
                    $order->commission_status = 2;
                    if (!$order->save()) throw new \RuntimeException('返佣状态保存失败');
                }, 3);
            } catch (\Throwable $e) {
                Log::error('返佣失败', ['trade_no' => $order->trade_no, 'exception' => $e]);
            }
        }
    }

    public function payHandle($inviteUserId, Order $order)
    {
        $level = 3;
        if ((int)config('v2board.commission_distribution_enable', 0)) {
            $commissionShareLevels = [
                0 => (int)config('v2board.commission_distribution_l1'),
                1 => (int)config('v2board.commission_distribution_l2'),
                2 => (int)config('v2board.commission_distribution_l3')
            ];
        } else {
            $commissionShareLevels = [
                0 => 100
            ];
        }
        for ($l = 0; $l < $level; $l++) {
            $inviter = User::find($inviteUserId);
            if (!$inviter) break;
            // Advance through the inviter chain even when this level receives
            // no share, or a later level would be credited to the same user.
            $inviteUserId = $inviter->invite_user_id;
            if (!isset($commissionShareLevels[$l])) continue;
            $commissionBalance = $order->commission_balance * ($commissionShareLevels[$l] / 100);
            if (!$commissionBalance) continue;
            $balanceField = (int)config('v2board.withdraw_close_enable', 0) ? 'balance' : 'commission_balance';
            if (!User::where('id', $inviter->id)->increment($balanceField, $commissionBalance)) return false;
            if (!CommissionLog::create([
                'invite_user_id' => $inviter->id,
                'user_id' => $order->user_id,
                'trade_no' => $order->trade_no,
                'order_amount' => $order->total_amount,
                'get_amount' => $commissionBalance
            ])) {
                return false;
            }
            // update order actual commission balance
            $order->actual_commission_balance = $order->actual_commission_balance + $commissionBalance;
        }
        return true;
    }

}
