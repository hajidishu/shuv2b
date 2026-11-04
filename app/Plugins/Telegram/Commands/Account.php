<?php
namespace App\Plugins\Telegram\Commands;
class Account extends \App\Plugins\Telegram\Telegram
{
    public $command='/account';
    public $description='查看套餐与到期时间';
    public function handle($message,$match=[]) {(new \App\Services\TelegramBotService($this->telegramService))->handleMessage($message);}
}
