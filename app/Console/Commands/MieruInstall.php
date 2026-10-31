<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MieruInstall extends Command
{
    protected $signature = 'mieru:install';
    protected $description = '补齐 Mieru 节点表（保留已有节点和业务数据）';
    public function handle()
    {
        app(\App\Services\MieruSchema::class)->ensure();
        $this->info('Mieru 节点表已就绪');
        return 0;
    }
}
