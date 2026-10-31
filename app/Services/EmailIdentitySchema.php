<?php

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmailIdentitySchema
{
    public function ensure(bool $checkOnly = false): array
    {
        return EmailIdentity::writing(function () use ($checkOnly) {
            $grammar = DB::connection()->getQueryGrammar();
            $stageName = 'email_identity_check_' . bin2hex(random_bytes(6)); $stage = $grammar->wrapTable($stageName);
            DB::statement('CREATE TEMPORARY TABLE ' . $stage . ' (user_id BIGINT PRIMARY KEY, email_hash CHAR(64), source_hash CHAR(64), email_version CHAR(32))');
            $invalid = []; $invalidCount = 0;
            try {
                $installed = EmailIdentity::installed();
                DB::table('v2_user')->select(['id','email'])->orderBy('id')->chunkById(1000, function ($users) use ($stageName, $installed, &$invalid, &$invalidCount) {
                    $previous = $installed ? DB::table(EmailIdentity::TABLE)->whereIn('user_id', $users->pluck('id')->all())->get()->keyBy('user_id') : [];
                    $rows = [];
                    foreach ($users as $user) {
                        try { $hash = EmailAddress::hash($user->email); }
                        catch (ValidationException $e) { $invalidCount++; if (count($invalid) < 100) $invalid[] = (int)$user->id; continue; }
                        $source = hash('sha256', $user->email); $old = $previous[$user->id] ?? null;
                        $same = $old && hash_equals($old->email_hash, $hash) && hash_equals($old->source_hash, $source);
                        $rows[] = ['user_id' => (int)$user->id, 'email_hash' => $hash, 'source_hash' => $source, 'email_version' => $same ? $old->email_version : bin2hex(random_bytes(16))];
                    }
                    foreach (array_chunk($rows, 200) as $chunk) DB::table($stageName)->insert($chunk);
                });
                $collisions = DB::table($stageName)->select('email_hash')->groupBy('email_hash')->havingRaw('COUNT(*) > 1')->limit(100)->get();
                if ($collisions->count()) {
                    $groups = []; foreach ($collisions as $collision) $groups[] = DB::table($stageName)->where('email_hash', $collision->email_hash)->pluck('user_id')->map(fn($id) => (int)$id)->all();
                    throw new \RuntimeException('邮箱规范化冲突，原账号未改写，请先核对用户 ID：' . json_encode($groups));
                }
                $report = ['indexed_users' => DB::table($stageName)->count(), 'invalid_legacy_count' => $invalidCount, 'invalid_legacy_user_ids' => $invalid, 'collisions' => 0, 'account_emails_rewritten' => false];
                if ($checkOnly) return $report;
                $schema = DB::getSchemaBuilder();
                if (!$schema->hasTable(EmailIdentity::TABLE)) $schema->create(EmailIdentity::TABLE, function (Blueprint $t) {
                    $t->unsignedInteger('user_id')->primary();
                    $t->char('email_hash', 64)->unique(); $t->char('source_hash', 64); $t->char('email_version', 32);
                });
                foreach (['user_id','email_hash','source_hash','email_version'] as $column) if (!$schema->hasColumn(EmailIdentity::TABLE, $column)) throw new \RuntimeException('邮箱身份表缺少字段：' . $column);
                DB::transaction(function () use ($stage, $grammar) {
                    DB::table(EmailIdentity::TABLE)->delete();
                    DB::statement('INSERT INTO ' . $grammar->wrapTable(EmailIdentity::TABLE) . ' (user_id,email_hash,source_hash,email_version) SELECT user_id,email_hash,source_hash,email_version FROM ' . $stage);
                }, 3);
                return $report;
            } finally { DB::statement((DB::connection()->getDriverName() === 'mysql' ? 'DROP TEMPORARY TABLE IF EXISTS ' : 'DROP TABLE IF EXISTS ') . $stage); }
        }, 3600);
    }
}
