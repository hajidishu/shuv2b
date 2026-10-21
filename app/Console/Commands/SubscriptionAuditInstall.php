<?php

namespace App\Console\Commands;

use App\Services\SubscriptionAuditSchema;
use Illuminate\Console\Command;

class SubscriptionAuditInstall extends Command
{
    protected $signature = 'subscribe-audit:install';
    protected $description = '创建并校验订阅审计数据表（可重复执行，不修改用户或订单）';

    public function handle(SubscriptionAuditSchema $schema)
    {
        $schema->ensure();
        $this->info('订阅审计数据表及索引校验通过');
        return 0;
    }
}
