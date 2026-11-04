<?php
namespace App\Jobs;

use App\Models\TelegramDelivery;
use App\Models\User;
use App\Services\TelegramBindingService;
use App\Services\TelegramReminderService;
use App\Services\TelegramService;
use App\Utils\Helper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class SendTelegramReminder implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public $tries=3;
    public $timeout=10;
    public $backoff=[15,60,300];
    private $id;
    public function __construct(int $id) {$this->id=$id;$this->onQueue('send_telegram');}
    public function handle(): void
    {
        \App\Services\TelegramRuntime::refresh();
        $lock=Cache::lock('TELEGRAM_DELIVERY_'.$this->id,15);
        if (!$lock->get()) {if ($this->job) $this->release(5);return;}
        try {
            $delivery=TelegramDelivery::find($this->id);
            if (!$delivery || $delivery->status!=='pending') return;
            $user=User::find($delivery->user_id);$profile=$user ? TelegramBindingService::confirmed($user) : null;
            $valid=config('v2board.telegram_remind_enable',0) && trim((string)config('v2board.telegram_bot_token'))!=='' && $profile &&
                $profile->binding_version===$delivery->binding_version && (string)$profile->telegram_id===(string)$delivery->telegram_id;
            $payload=$delivery->payload;
            if ($valid && $delivery->kind==='expiry') $valid=$profile->remind_expire && (int)$user->expired_at===(int)$payload['expiry'] && (int)$user->expired_at>time()-86400;
            elseif ($valid && $delivery->kind==='traffic') $valid=$profile->remind_traffic &&
                (int)$profile->traffic_cycle===(int)$payload['cycle'] && (int)$user->transfer_enable===(int)$payload['limit'] &&
                (int)$user->plan_id===(int)$payload['plan'] && (string)$user->expired_at===(string)$payload['expiry'] &&
                (int)$user->u+(int)$user->d>=(int)$payload['usage'] && TelegramReminderService::lowTraffic($user) && (new \App\Services\UserService())->isAvailable($user);
            elseif ($valid) $valid=false;
            if (!$valid || $delivery->attempts>=5) {$delivery->status='canceled';$delivery->save();return;}
            $delivery->attempts=(int)$delivery->attempts+1;$delivery->save();
            $text=$delivery->kind==='expiry' ? '套餐'.((int)$user->expired_at>time()?'即将到期':'已到期').'：'.date('Y-m-d H:i',(int)$user->expired_at)
                : '套餐剩余流量不足：'.Helper::trafficConvert(max(0,(int)$user->transfer_enable-(int)$user->u-(int)$user->d));
            try {app(TelegramService::class)->sendPanelMessage((int)$delivery->telegram_id,$text,[[['text'=>'打开账户面板','callback_data'=>'v2b:home']]]);}
            catch (\App\Exceptions\TelegramApiException $e) {
                if ($e->getCode()===429) {$delivery->attempts=max(0,(int)$delivery->attempts-1);$delivery->save();}
                if (in_array($e->getCode(),[400,403],true) || $delivery->attempts>=5) {$delivery->status='failed';$delivery->save();return;}
                if ($e->retryAfter && $this->job) {$this->release(min(3600,max(1,$e->retryAfter)));return;}
                throw $e;
            }
            $delivery->status='sent';$delivery->sent_at=time();$delivery->save();
        } finally {$lock->release();}
    }
}
