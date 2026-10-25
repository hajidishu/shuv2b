<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\PasskeySchema;

class PasskeyInstall extends Command
{
    protected $signature = 'v2board:passkey-install';
    protected $description = '安装或检查 Passkey 数据库结构';
    public function handle()
    {
        app(PasskeySchema::class)->ensure();
        $this->info('Passkey 数据库结构已就绪；请在安全配置中设置 RP ID 和允许来源后启用。');
        return 0;
    }
}
