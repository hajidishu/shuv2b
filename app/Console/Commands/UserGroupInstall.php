<?php
namespace App\Console\Commands;

class UserGroupInstall extends \Illuminate\Console\Command
{
    protected $signature='user-group:install';
    protected $description='安装用户自定义权限组字段';
    public function handle() {app(\App\Services\UserGroupSchema::class)->ensure();$this->info('用户权限组字段已就绪');return 0;}
}
