<?php
namespace App\Console\Commands;
use App\Services\LeakProbeSchema;
use Illuminate\Console\Command;
class LeakProbeInstall extends Command
{
    protected $signature = 'leak-probe:install';
    protected $description = '安装并校验人工内鬼排查数据表';
    public function handle(LeakProbeSchema $schema) { $schema->ensure(); $this->info('内鬼排查数据表校验通过'); return 0; }
}
