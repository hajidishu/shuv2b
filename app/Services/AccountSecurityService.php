<?php

namespace App\Services;

use App\Models\User;

class AccountSecurityService
{
    public static function recover(User $user): void
    {
        GoogleIdentityService::purgeUser((int)$user->id);
        PasskeyService::revokeOnRecovery($user);
    }

    public static function purgeUser(int $id): void
    {
        EmailIdentity::remove($id);
        GoogleIdentityService::purgeUser($id);
        PasskeyService::purgeUser($id);
    }
}
