<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;

class UnBind extends Telegram {
    public $command = '/unbind';
    public $description = '将Telegram账号从网站解绑';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        if (\App\Services\TelegramService::managementUsers()->where('telegram_admin_id', $message->chat_id)->exists()) {
            abort(403, '管理账号请在后台清空 Telegram 管理账号 ID 解除绑定');
        }
        $user = User::where('telegram_id', $message->chat_id)->first();
        $telegramService = $this->telegramService;
        if (!$user) {
            $telegramService->sendMessage($message->chat_id, '没有查询到您的用户信息，请先绑定账号', 'markdown');
            return;
        }
        $user->telegram_id = NULL;
        if (!$user->save()) {
            abort(500, '解绑失败');
        }
        $telegramService->sendMessage($message->chat_id, '解绑成功', 'markdown');
    }
}
