<?php

namespace App\Console\Commands;

use App\Services\SubscriptionAuditService;
use Illuminate\Console\Command;

class SubscriptionAuditPrune extends Command
{
    protected $signature = 'subscribe-audit:prune';
    protected $description = '按保留天数和最大条数分批清理订阅审计';

    public function handle(SubscriptionAuditService $audit)
    {
        if (!config('subscription_audit.enabled', true)) return 0;
        $this->info('已清理 ' . $audit->prune() . ' 条订阅审计记录');
        return 0;
    }
}
