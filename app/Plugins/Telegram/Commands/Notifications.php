<?php
namespace App\Plugins\Telegram\Commands;
class Notifications extends \App\Plugins\Telegram\Telegram
{
    public $command='/notifications';
    public $description='设置到期和流量提醒';
    public function handle($message,$match=[]) {(new \App\Services\TelegramBotService($this->telegramService))->handleMessage($message);}
}
