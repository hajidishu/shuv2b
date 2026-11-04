<?php
namespace App\Exceptions;

class TelegramApiException extends \RuntimeException
{
    public $retryAfter;
    public function __construct(int $code = 0, int $retryAfter = 0)
    {
        parent::__construct('Telegram 服务暂时不可用，请稍后重试', $code);
        $this->retryAfter = $retryAfter;
    }
}
