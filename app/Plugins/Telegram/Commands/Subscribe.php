<?php
namespace App\Plugins\Telegram\Commands;
class Subscribe extends \App\Plugins\Telegram\Telegram
{
    public $command='/subscribe';
    public $description='获取当前订阅';
    public function handle($message,$match=[]) {(new \App\Services\TelegramBotService($this->telegramService))->handleMessage($message);}
}
