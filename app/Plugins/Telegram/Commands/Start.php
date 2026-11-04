<?php
namespace App\Plugins\Telegram\Commands;
class Start extends \App\Plugins\Telegram\Telegram
{
    public $command='/start';
    public $description='打开账户面板';
    public function handle($message,$match=[]) {(new \App\Services\TelegramBotService($this->telegramService))->handleMessage($message);}
}
