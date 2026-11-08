<?php

namespace App\Services;

use App\Models\User;

class AccountSecurityService
{
    public static function recover(User $user): void
    {
        TelegramBindingService::revokeConfirmation((int)$user->id);
        if ($user->telegram_admin_id !== null) {
            $user->telegram_admin_id = null;
            $user->telegram_admin_version = (int)$user->telegram_admin_version + 1;
            $user->save();
        }
        GoogleIdentityService::purgeUser((int)$user->id);
        PasskeyService::revokeOnRecovery($user);
    }

    public static function purgeUser(int $id): void
    {
        if (TelegramBotSchema::installed()) {
            TelegramBindingService::revokeConfirmation($id);
            \App\Models\TelegramDelivery::where('user_id',$id)->delete();
        }
        EmailIdentity::remove($id);
        GoogleIdentityService::purgeUser($id);
        PasskeyService::purgeUser($id);
    }
}
