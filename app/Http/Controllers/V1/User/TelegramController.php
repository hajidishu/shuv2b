<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    public function getBotInfo(Request $request)
    {
        $user=User::findOrFail($request->user['id']);
        $enabled=is_string(config('v2board.telegram_bot_token')) && trim(config('v2board.telegram_bot_token'))!=='';
        $profile=\App\Services\TelegramBindingService::confirmed($user);
        return response([
            'data' => [
                'enabled'=>$enabled,
                'username'=>$enabled ? app(TelegramService::class)->botUsername() : null,
                'telegram_id'=>$user->telegram_id ? (string)$user->telegram_id : null,
                'confirmed'=>$profile!==null,
                'can_bind'=>!$user->is_admin && !$user->is_staff && !$user->banned,
            ]
        ]);
    }

    public function bind(Request $request)
    {
        $user=User::findOrFail($request->user['id']);
        abort_unless(is_string(config('v2board.telegram_bot_token')) && trim(config('v2board.telegram_bot_token'))!=='',422,'站点尚未配置 Telegram 机器人');
        $username=app(TelegramService::class)->botUsername();
        $nonce=\App\Services\TelegramBindingService::issue($user);
        return response(['data'=>['url'=>'https://t.me/'.$username.'?start=bind_'.$nonce,'expires_in'=>300]]);
    }

    public function unbind(Request $request)
    {
        $user=User::findOrFail($request->user['id']);
        \App\Services\TelegramBindingService::unbind($user);
        return response(['data'=>true]);
    }
}
