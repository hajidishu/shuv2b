<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Cache\LockTimeoutException;

class EmailIdentity
{
    public const TABLE = 'v2_user_email_identity';
    public const WRITE_LOCK = 'EMAIL_IDENTITY_WRITE_V1';

    public static function installed(): bool { return DB::getSchemaBuilder()->hasTable(self::TABLE); }

    public static function find($email, bool $lock = false): ?User
    {
        $key = EmailAddress::key($email); $hash = hash('sha256', $key);
        if (self::installed()) {
            $identity = DB::table(self::TABLE)->where('email_hash', $hash)->first();
            if (!$identity) return null;
            $query = User::where('id', $identity->user_id);
            if ($lock) $query->lockForUpdate();
            $user = $query->first();
            if (!$user) {
                DB::table(self::TABLE)->where('user_id', $identity->user_id)->whereNotExists(function ($q) {
                    $q->selectRaw('1')->from('v2_user')->whereColumn('v2_user.id', self::TABLE . '.user_id');
                })->delete();
                return null;
            }
            if (!hash_equals($hash, EmailAddress::hash($user->email)) || !hash_equals($identity->source_hash, hash('sha256', $user->email))) return null;
            return $user;
        }
        // Safe pre-migration lookup: SQL is only a candidate search, never proof
        // that two mailboxes are the same. Recovery remains disabled until indexed.
        $variants = EmailAddress::candidates($email);
        $lower = array_values(array_unique(array_map('strtolower', $variants)));
        $query = User::where(function ($q) use ($variants, $lower) {
            $q->whereIn('email', $variants)->orWhereRaw('LOWER(email) IN (' . implode(',', array_fill(0, count($lower), '?')) . ')', $lower);
        });
        if ($lock) $query->lockForUpdate();
        $users = $query->limit(100)->get(); $matches = [];
        if ($users->count() >= 100) abort(409, '邮箱标识冲突，请联系管理员');
        foreach ($users as $user) if (hash_equals($hash, EmailAddress::hash($user->email))) $matches[] = $user;
        if (count($matches) > 1) abort(409, '邮箱标识冲突，请联系管理员');
        return $matches[0] ?? null;
    }

    public static function assertAvailable($email, ?int $exceptId = null): void
    {
        $stored = EmailAddress::normalize($email);
        $matched = self::find($email);
        if ($matched && (int)$matched->id !== $exceptId) abort(500, __('Email already exists'));
        // Retain the old database's uniqueness constraint conservatively, without
        // treating its accent-insensitive match as the same account identity.
        $query = User::where('email', $stored);
        if ($exceptId) $query->where('id', '!=', $exceptId);
        if ($query->exists()) abort(500, __('Email already exists'));
    }

    public static function synchronize(User $user): void
    {
        if (!self::installed()) return;
        $previous = DB::table(self::TABLE)->where('user_id', $user->id)->lockForUpdate()->first();
        $hash = EmailAddress::hash($user->email); $source = hash('sha256', $user->email);
        $same = $previous && hash_equals($previous->email_hash, $hash) && hash_equals($previous->source_hash, $source);
        $values = ['email_hash' => $hash, 'source_hash' => $source, 'email_version' => $same ? $previous->email_version : bin2hex(random_bytes(16))];
        if ($previous) DB::table(self::TABLE)->where('user_id', $user->id)->update($values);
        else DB::table(self::TABLE)->insert(['user_id' => $user->id] + $values);
    }

    public static function current(User $user)
    {
        if (!self::installed()) abort(503, '请先运行邮箱身份数据库升级');
        $row = DB::table(self::TABLE)->where('user_id', $user->id)->lockForUpdate()->first();
        if (!$row || !hash_equals($row->source_hash, hash('sha256', $user->email)) || !hash_equals($row->email_hash, EmailAddress::hash($user->email))) abort(503, '邮箱身份需要重新校验');
        return $row;
    }

    public static function remove(int $userId): void { if (self::installed()) DB::table(self::TABLE)->where('user_id', $userId)->delete(); }

    public static function writing(callable $operation, int $seconds = 30)
    {
        try { return Cache::lock(self::WRITE_LOCK, $seconds)->block(3, $operation); }
        catch (LockTimeoutException $e) { abort(503, '邮箱正在更新，请稍后重试'); }
    }
}
