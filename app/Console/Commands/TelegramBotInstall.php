<?php
namespace App\Console\Commands;

class TelegramBotInstall extends \Illuminate\Console\Command
{
    protected $signature='telegram-bot:install';
    protected $description='补齐 Telegram 自助服务数据表';
    public function handle() {app(\App\Services\TelegramBotSchema::class)->ensure();$this->info('Telegram 数据表已就绪');return 0;}
}
