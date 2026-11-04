<?php
namespace App\Console\Commands;

class TelegramMaintain extends \Illuminate\Console\Command
{
    protected $signature='telegram:maintain';
    protected $description='生成 Telegram 到期/流量提醒并维护受管群成员';
    public function handle()
    {
        app(\App\Services\TelegramReminderService::class)->maintain();
        app(\App\Services\TelegramGroupService::class)->prune();
        return 0;
    }
}
