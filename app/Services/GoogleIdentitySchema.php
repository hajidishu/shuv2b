<?php

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

class GoogleIdentitySchema
{
    public static function installed(): bool
    {
        return DB::connection()->getSchemaBuilder()->hasTable('v2_social_identity');
    }

    public function ensure(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        if (!$schema->hasTable('v2_social_identity')) $schema->create('v2_social_identity', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('user_id')->index();
            $t->string('provider', 16);
            $t->char('subject_hash', 64)->unique();
            $t->string('subject', 255);
            $t->string('email', 254);
            $t->unsignedBigInteger('created_at');
            $t->unsignedBigInteger('last_login_at')->nullable();
            $t->unique(['user_id', 'provider']);
        });
        foreach (['id', 'user_id', 'provider', 'subject_hash', 'subject', 'email', 'created_at', 'last_login_at'] as $column) {
            if (!$schema->hasColumn('v2_social_identity', $column)) throw new \RuntimeException('第三方身份数据库结构不完整：' . $column);
        }
    }
}
