<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PasskeySchema;
use App\Services\PasskeyService;
use Illuminate\Console\Command;

class PasskeyRevoke extends Command
{
    protected $signature = 'v2board:passkey-revoke {email}';
    protected $description = '撤销指定账号全部 Passkey 和登录会话（保留密码及订阅信息）';
    public function handle()
    {
        if (!PasskeySchema::installed()) { $this->error('请先安装 Passkey 数据库结构'); return 1; }
        $user = User::where('email', $this->argument('email'))->first();
        if (!$user) { $this->error('账号不存在'); return 1; }
        $count = PasskeyService::revokeUser((int)$user->id);
        $this->info("已撤销 {$count} 个 Passkey 及该账号的全部登录会话。");
        return 0;
    }
}
