<?php

namespace App\Plugins\Telegram\Commands;

use App\Plugins\Telegram\Telegram;
use App\Services\TicketService;
use App\Services\TelegramService;
use Illuminate\Support\Facades\DB;

class ReplyTicket extends Telegram {
    public $regex = '/[#](.*)/';
    public $description = '快速工单回复';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        $this->replayTicket($message, $match[1]);
    }


    private function replayTicket($msg, $ticketId)
    {
        if (!$msg->text) return;
        $user = DB::transaction(function () use ($msg, $ticketId) {
            $user = TelegramService::managementUsers()->where('telegram_admin_id', $msg->chat_id)->lockForUpdate()->first();
            if (!$user) abort(403, '请由管理员在后台设置 Telegram 管理账号 ID');
            (new TicketService())->replyByAdmin($ticketId, $msg->text, $user->id);
            return $user;
        });
        $telegramService = $this->telegramService;
        $telegramService->sendMessage($msg->chat_id, "#`{$ticketId}` 的工单已回复成功", 'markdown');
        $telegramService->sendMessageWithAdmin("#`{$ticketId}` 的工单已由 {$user->email} 进行回复", true);
    }
}
