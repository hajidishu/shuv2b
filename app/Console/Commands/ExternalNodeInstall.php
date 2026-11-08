<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ExternalNodeInstall extends Command
{
    protected $signature = 'external-node:install';
    protected $description = '补齐权限组外部节点表（保留已有数据）';

    public function handle()
    {
        app(\App\Services\ExternalNodeSchema::class)->ensure();
        $this->info('权限组外部节点表已就绪');
        return 0;
    }
}
