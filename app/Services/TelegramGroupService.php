<?php
namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TelegramGroupService
{
    public static function validIds($value): bool
    {
        if ((!is_string($value) && !is_int($value)) || strlen((string)$value)>1024) return false;
        $parts=preg_split('/[\s,]+/',trim((string)$value),-1,PREG_SPLIT_NO_EMPTY);
        return !$parts || count(array_filter($parts,function ($id) {return preg_match('/\A-?[1-9][0-9]{0,15}\z/',$id);}))===count($parts);
    }
    public static function chatIds(): array
    {
        $parts=preg_split('/[\s,]+/',(string)config('v2board.telegram_discuss_id','').','.(string)config('v2board.telegram_channel_id',''));
        return array_values(array_unique(array_map('intval',array_filter($parts,function ($id) {return preg_match('/\A-?[1-9][0-9]{0,15}\z/',$id);})),SORT_REGULAR));
    }

    private function eligible(?User $user): bool
    {
        return $user && !$user->is_admin && !$user->is_staff && (new UserService())->isAvailable($user) &&
            (!config('v2board.telegram_group_prune_enable',0) || TelegramBindingService::confirmed($user));
    }

    private function approve(int $chatId,int $telegramId,int $userId,array $ids): void
    {
        // Record the intended membership before the remote action so a worker crash cannot lose cleanup ownership.
        if (in_array($chatId,$ids,true) && TelegramBotSchema::installed()) DB::table('v2_telegram_member')->updateOrInsert(
            ['chat_id'=>$chatId,'telegram_id'=>$telegramId],['user_id'=>$userId,'joined_at'=>time()]);
        $telegram=app(TelegramService::class);
        try {$telegram->approveChatJoinRequest($chatId,$telegramId);}
        catch (\App\Exceptions\TelegramApiException $e) {
            if ($e->getCode()!==400) throw $e;
            $member=$telegram->getChatMember($chatId,$telegramId)->result ?? null;
            if (!$member || (!in_array($member->status ?? '',['member','creator','administrator'],true) && !(($member->status ?? '')==='restricted' && ($member->is_member ?? false)))) throw $e;
        }
    }

    public function join(array $request): void
    {
        $telegramId=$request['from']['id'] ?? null;$chatId=$request['chat']['id'] ?? null;
        if (!is_int($telegramId) || $telegramId<=0 || !is_int($chatId)) return;
        $ids=self::chatIds();
        $configured=trim((string)config('v2board.telegram_discuss_id','').(string)config('v2board.telegram_channel_id',''))!=='';
        if (($configured && !in_array($chatId,$ids,true)) || (!$ids && config('v2board.telegram_group_prune_enable',0))) return;
        $users=User::where('telegram_id',$telegramId)->limit(2)->get();$user=$users->count()===1?$users->first():null;
        $telegram=app(TelegramService::class);
        $admin=TelegramService::managementUsers()->where('telegram_admin_id',$telegramId)->first();
        if ($admin) {
            $this->approve($chatId,$telegramId,(int)$admin->id,$ids);
            return;
        }
        if (!$this->eligible($user)) {$telegram->declineChatJoinRequest($chatId,$telegramId);return;}
        $this->approve($chatId,$telegramId,(int)$user->id,$ids);
    }

    public function prune(): void
    {
        if (!config('v2board.telegram_group_prune_enable',0) || !TelegramBotSchema::installed() || trim((string)config('v2board.telegram_bot_token'))==='') return;
        $ids=self::chatIds();if (!$ids) return;
        $key='TELEGRAM_MEMBER_CURSOR_'.hash('sha256',json_encode($ids));
        $cursor=(int)\Illuminate\Support\Facades\Cache::get($key,0);
        if (!DB::table('v2_telegram_member')->whereIn('chat_id',$ids)->where('id','>',$cursor)->exists()) $cursor=0;
        $actions=0;$last=$cursor;
        DB::table('v2_telegram_member')->whereIn('chat_id',$ids)->where('id','>',$cursor)->orderBy('id')->chunkById(50,function ($members) use (&$actions,&$last) {
            $telegram=app(TelegramService::class);
            foreach ($members as $member) {
                $previous=$last;$last=(int)$member->id;
                $user=User::find($member->user_id);
                if (TelegramService::managementUsers()->where('id',$member->user_id)->where('telegram_admin_id',$member->telegram_id)->exists()) continue;
                if ($this->eligible($user) && (string)$user->telegram_id===(string)$member->telegram_id) continue;
                if (++$actions>100) {$last=$previous;return false;}
                try {
                    $status=$telegram->getChatMember((int)$member->chat_id,(int)$member->telegram_id)->result->status ?? '';
                    if (in_array($status,['creator','administrator'],true)) continue;
                    if (!in_array($status,['left','kicked'],true)) $telegram->removeChatMember((int)$member->chat_id,(int)$member->telegram_id);
                    DB::table('v2_telegram_member')->where('id',$member->id)->delete();
                } catch (\App\Exceptions\TelegramApiException $e) {
                    Log::warning('Telegram membership update failed',['member_id'=>$member->id,'code'=>$e->getCode()]);
                    if ($e->getCode()===429) {$last=$previous;return false;}
                }
            }
        });
        \Illuminate\Support\Facades\Cache::put($key,$last,86400);
    }
}
