<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\EmailIdentitySchema;

class EmailIdentityInstall extends Command
{
    protected $signature = 'email-identity:install {--check : 只检查规范化碰撞，不修改账号或身份表}';
    protected $description = '检查邮箱碰撞并补齐精确邮箱身份索引，不批量改写账号邮箱';
    public function handle(EmailIdentitySchema $schema)
    {
        $report = $schema->ensure((bool)$this->option('check'));
        $this->info(json_encode($report, JSON_UNESCAPED_UNICODE));
        return 0;
    }
}
