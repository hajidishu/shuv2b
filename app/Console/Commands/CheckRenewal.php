<?php

namespace App\Console\Commands;

use App\Services\PlanService;
use App\Services\OrderService;
use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Order;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class CheckRenewal extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:renewal';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '自动续费';

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
        ini_set('memory_limit', -1);
        $userIds = User::where('auto_renewal', 1)->pluck('id');
        foreach ($userIds as $userId) {
            try {
                DB::transaction(function () use ($userId) {
                    $user = User::where('id', $userId)->lockForUpdate()->first();
                    if (!$user || !$user->auto_renewal || $user->plan_id === null ||
                        $user->expired_at === null || $user->expired_at <= time() ||
                        $user->expired_at - time() >= 86400 * 2) return;

                    try {
                        $latestOrder = Order::where('user_id', $user->id)
                            ->where('plan_id', $user->plan_id)
                            ->whereNotIn('period', ['reset_price', 'onetime_price', 'deposit'])
                            ->where('status', 3)->orderBy('created_at', 'desc')->first();
                        if (!$latestOrder || !isset(OrderService::STR_TO_TIME[$latestOrder->period])) {
                            throw new \DomainException('No valid renewal period');
                        }
                        $period = $latestOrder->period;
                        $plan = (new PlanService($user->plan_id))->plan;
                        if (!$plan || !$plan->renew || $plan[$period] === null || $plan[$period] < 0) {
                            throw new \DomainException('Subscription cannot be renewed');
                        }
                        $order = new Order();
                        $order->total_amount = $plan[$period];
                        (new OrderService($order))->setVipDiscount($user);
                        $amount = $order->total_amount;
                        if ($user->balance < $amount) throw new \DomainException('Insufficient balance');
                    } catch (\DomainException $e) {
                        $user->auto_renewal = 0;
                        if (!$user->save()) throw new \RuntimeException('自动续费设置保存失败');
                        return;
                    }

                    $order->user_id = $user->id;
                    $order->plan_id = $plan->id;
                    $order->period = $period;
                    $order->trade_no = Helper::generateOrderNo();
                    $order->balance_amount = $amount;
                    $order->total_amount = 0;
                    $order->type = 2;
                    $order->status = 3;
                    $user->balance -= $amount;
                    $user->expired_at = $this->getTime($period, $user->expired_at);
                    if (!$user->save() || !$order->save()) throw new \RuntimeException('自动续费失败');
                }, 3);
            } catch (\Throwable $e) {
                Log::error('自动续费失败', ['user_id' => $userId, 'exception' => $e]);
            }
        }
    }

    private function getTime($str, $timestamp)
    {
        if ($timestamp < time()) {
            $timestamp = time();
        }
        switch ($str) {
            case 'month_price':
                return strtotime('+1 month', $timestamp);
            case 'quarter_price':
                return strtotime('+3 month', $timestamp);
            case 'half_year_price':
                return strtotime('+6 month', $timestamp);
            case 'year_price':
                return strtotime('+12 month', $timestamp);
            case 'two_year_price':
                return strtotime('+24 month', $timestamp);
            case 'three_year_price':
                return strtotime('+36 month', $timestamp);
        }
    }
}
