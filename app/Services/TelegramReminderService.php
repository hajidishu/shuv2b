<?php
namespace App\Services;

use App\Jobs\SendTelegramReminder;
use App\Models\TelegramDelivery;
use App\Models\TelegramProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TelegramReminderService
{
    public static function lowTraffic(User $user): bool
    {
        $limit=(int)$user->transfer_enable;
        return $limit>0 && max(0,$limit-(int)$user->u-(int)$user->d)*100 <= $limit*(int)config('v2board.telegram_remind_traffic_percent',10);
    }

    public function maintain(): void
    {
        if (!TelegramBotSchema::installed()) return;
        if (config('v2board.telegram_remind_enable',0) && trim((string)config('v2board.telegram_bot_token'))!=='') {
            TelegramProfile::query()->chunkById(100,function ($profiles) {
                foreach ($profiles as $profile) $this->collect((int)$profile->user_id);
            },'user_id');
            TelegramDelivery::where('status','pending')->where('attempts','<',5)
                ->where(function ($q) {$q->whereNull('dispatched_at')->orWhere('dispatched_at','<',time()-600);})
                ->chunkById(100,function ($deliveries) {
                    foreach ($deliveries as $delivery) {
                        $delivery->dispatched_at=time();$delivery->save();
                        try {SendTelegramReminder::dispatch((int)$delivery->id);}
                        catch (\Throwable $e) {
                            $delivery->dispatched_at=null;$delivery->save();
                            Log::warning('Telegram reminder queue unavailable',['delivery_id'=>$delivery->id]);
                        }
                    }
                });
        }
        TelegramDelivery::whereIn('status',['sent','canceled','failed'])->where('updated_at','<',time()-30*86400)->delete();
        TelegramDelivery::where('status','pending')->where('created_at','<',time()-30*86400)->delete();
    }

    private function collect(int $userId): void
    {
        DB::transaction(function () use ($userId) {
            $profile=TelegramProfile::where('user_id',$userId)->lockForUpdate()->first();if (!$profile) return;
            $user=User::find($userId);if (!$user || !TelegramBindingService::confirmed($user,$profile)) return;
            $usage=(int)$user->u+(int)$user->d;
            if ($usage<(int)$profile->last_usage || (int)$user->transfer_enable!==(int)$profile->last_limit ||
                (int)$user->plan_id!==(int)$profile->last_plan || (string)$user->expired_at!==(string)$profile->last_expiry) $profile->traffic_cycle=(int)$profile->traffic_cycle+1;
            $profile->last_usage=$usage;$profile->last_limit=(int)$user->transfer_enable;
            $profile->last_plan=(int)$user->plan_id;$profile->last_expiry=$user->expired_at;$profile->save();
            $expiry=(int)$user->expired_at;
            if ($profile->remind_expire && $expiry>time()-86400 && $expiry<=time()+86400*(int)config('v2board.telegram_remind_expire_days',3))
                $this->record($profile,'expiry',(string)$expiry,['expiry'=>$expiry]);
            if ($profile->remind_traffic && (new UserService())->isAvailable($user) && self::lowTraffic($user))
                $this->record($profile,'traffic',(string)$profile->traffic_cycle,['cycle'=>(int)$profile->traffic_cycle,'limit'=>(int)$user->transfer_enable,'usage'=>$usage,'plan'=>(int)$user->plan_id,'expiry'=>$user->expired_at]);
        });
    }

    private function record(TelegramProfile $profile,string $kind,string $cycle,array $payload): void
    {
        TelegramDelivery::firstOrCreate(['event_key'=>hash('sha256',$profile->user_id.':'.$profile->binding_version.':'.$kind.':'.$cycle)],
            ['user_id'=>$profile->user_id,'telegram_id'=>$profile->telegram_id,'binding_version'=>$profile->binding_version,'kind'=>$kind,'payload'=>$payload,'status'=>'pending','attempts'=>0]);
    }
}
