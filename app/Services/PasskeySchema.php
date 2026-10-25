<?php

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

class PasskeySchema
{
    public function ensure(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        if (!$schema->hasTable('v2_passkey_user')) $schema->create('v2_passkey_user', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('user_id');
            $t->string('rp_id', 253);
            $t->char('user_handle', 43)->unique();
            $t->unsignedBigInteger('created_at');
            $t->index('user_id');
            // The user row is locked while creating a mapping; avoid a large utf8mb4 composite index.
        });
        if (!$schema->hasTable('v2_passkey_credential')) $schema->create('v2_passkey_credential', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('passkey_user_id')->index();
            $t->char('credential_hash', 64)->unique();
            $t->text('credential_id');
            $t->text('public_key');
            $t->string('name', 80);
            $t->unsignedBigInteger('sign_count')->default(0);
            $t->boolean('backup_eligible')->default(false);
            $t->boolean('backed_up')->default(false);
            $t->text('transports')->nullable();
            $t->unsignedBigInteger('created_at');
            $t->unsignedBigInteger('last_used_at')->nullable();
            $t->unsignedBigInteger('revoked_at')->nullable();
        });
        foreach ([
            'v2_passkey_user' => ['id', 'user_id', 'rp_id', 'user_handle', 'created_at'],
            'v2_passkey_credential' => ['id', 'passkey_user_id', 'credential_hash', 'credential_id', 'public_key', 'name', 'sign_count', 'backup_eligible', 'backed_up', 'transports', 'created_at', 'last_used_at', 'revoked_at'],
        ] as $table => $columns) foreach ($columns as $column) {
            if (!$schema->hasColumn($table, $column)) throw new \RuntimeException('Passkey 数据库结构不完整：' . $table . '.' . $column);
        }
    }

    public static function installed(): bool
    {
        $schema = DB::connection()->getSchemaBuilder();
        return $schema->hasTable('v2_passkey_user') && $schema->hasTable('v2_passkey_credential');
    }
}
