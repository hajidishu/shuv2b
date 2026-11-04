<?php
namespace App\Services;

use App\Models\User;
use App\Models\TelegramProfile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TelegramBindingService
{
    public static function fingerprint(User $user): string
    {
        return hash('sha256', json_encode([$user->id,$user->email,$user->password,$user->token,$user->uuid]));
    }

    public static function confirmed(User $user, ?TelegramProfile $profile = null): ?TelegramProfile
    {
        if ($user->banned || $user->is_admin || $user->is_staff || !$user->telegram_id) return null;
        if ($profile===null) {
            if (!TelegramBotSchema::installed()) return null;
            $profile=TelegramProfile::find($user->id);
        }
        return $profile && (string)$profile->telegram_id === (string)$user->telegram_id &&
            hash_equals($profile->fingerprint,self::fingerprint($user)) ? $profile : null;
    }

    public static function issue(User $user): string
    {
        abort_if($user->banned || $user->is_admin || $user->is_staff,403,'仅普通用户可在此绑定；管理员请在后台设置 TG ID');
        abort_unless(TelegramBotSchema::installed(),503,'请先运行面板更新程序');
        return DB::transaction(function () use ($user) {
            $locked=User::where('id',$user->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->banned || $locked->is_admin || $locked->is_staff,403,'当前账号不能绑定');
            $nonce = bin2hex(random_bytes(16));
            self::forgetChallenge((int)$locked->id);
            Cache::put('TELEGRAM_BIND_'.$nonce,['user_id'=>(int)$locked->id,'fingerprint'=>self::fingerprint($locked)],300);
            Cache::put('TELEGRAM_BIND_USER_'.$locked->id,$nonce,300);
            return $nonce;
        });
    }

    public static function challenge(string $nonce): ?array
    {
        if (!preg_match('/\A[0-9a-f]{32}\z/',$nonce)) return null;
        $value = Cache::get('TELEGRAM_BIND_'.$nonce);
        return is_array($value) ? $value : null;
    }

    public static function confirm(string $nonce, int $telegramId): User
    {
        abort_if($telegramId <= 0,422,'Telegram 用户无效');
        return Cache::lock('TELEGRAM_BIND_CONFIRM_'.$nonce,15)->block(3,function () use ($nonce,$telegramId) {
            $challenge = self::challenge($nonce);
            abort_unless($challenge,422,'绑定链接已过期，请从用户中心重新打开');
            return Cache::lock('TELEGRAM_OWNER_'.$telegramId,15)->block(3,function () use ($challenge,$nonce,$telegramId) {
                return DB::transaction(function () use ($challenge,$nonce,$telegramId) {
                    $user = User::where('id',$challenge['user_id'])->lockForUpdate()->first();
                    $challenge=self::challenge($nonce);
                    abort_unless($user && !$user->banned && !$user->is_admin && !$user->is_staff &&
                        $challenge && hash_equals($challenge['fingerprint'],self::fingerprint($user)),403,'账号状态已变化，请重新确认绑定');
                    abort_if(isset($challenge['used_by']) && (int)$challenge['used_by'] !== $telegramId,403,'绑定链接已被使用');
                    abort_if($user->telegram_id && (string)$user->telegram_id !== (string)$telegramId,409,'该账号已绑定其他 Telegram，请先在用户中心解绑');
                    abort_if(User::where('telegram_id',$telegramId)->where('id','!=',$user->id)->exists(),409,'此 Telegram 已绑定其他账号，请先解除原绑定');
                    abort_if(TelegramService::managementUsers()->where('telegram_admin_id',$telegramId)->exists(),403,'管理账号请使用后台设置的 TG ID');
                    if (isset($challenge['used_by']) && self::confirmed($user)) return $user;
                    $user->telegram_id = $telegramId; $user->save();
                    TelegramProfile::updateOrCreate(['user_id'=>$user->id],[
                        'telegram_id'=>$telegramId,'binding_version'=>bin2hex(random_bytes(16)),'fingerprint'=>self::fingerprint($user),
                        'remind_expire'=>1,'remind_traffic'=>1,'traffic_cycle'=>0,'last_usage'=>(int)$user->u+(int)$user->d,
                        'last_limit'=>(int)$user->transfer_enable,'last_plan'=>(int)$user->plan_id,'last_expiry'=>$user->expired_at,
                    ]);
                    $challenge['used_by'] = $telegramId; Cache::put('TELEGRAM_BIND_'.$nonce,$challenge,300);
                    return $user;
                });
            });
        });
    }

    public static function unbind(User $user): void
    {
        DB::transaction(function () use ($user) {
            $locked = User::where('id',$user->id)->lockForUpdate()->firstOrFail();
            $locked->telegram_id = null; $locked->save();
            self::revokeConfirmation((int)$user->id);
        });
    }

    private static function forgetChallenge(int $userId): void
    {
        $nonce=Cache::pull('TELEGRAM_BIND_USER_'.$userId);
        if (is_string($nonce)) Cache::forget('TELEGRAM_BIND_'.$nonce);
    }

    public static function revokeConfirmation(int $userId): void
    {
        DB::transaction(function () use ($userId) {
            User::where('id',$userId)->lockForUpdate()->first();
            if (TelegramBotSchema::installed()) TelegramProfile::where('user_id',$userId)->delete();
            self::forgetChallenge($userId);
        });
    }
}
