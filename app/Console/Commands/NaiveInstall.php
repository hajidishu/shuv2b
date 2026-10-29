<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class NaiveInstall extends Command
{
    protected $signature = 'naive:install';
    protected $description = '补齐 Naive 节点表（保留已有节点和业务数据）';
    public function handle()
    {
        app(\App\Services\NaiveSchema::class)->ensure();
        $this->info('Naive 节点表已就绪');
        return 0;
    }
}
