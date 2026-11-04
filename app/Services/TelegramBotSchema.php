<?php

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

class TelegramBotSchema
{
    public function ensure(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        if (!$schema->hasTable('v2_telegram_profile')) $schema->create('v2_telegram_profile', function (Blueprint $t) {
            $t->unsignedInteger('user_id')->primary(); $t->bigInteger('telegram_id')->unique();
            $t->string('binding_version',32); $t->string('fingerprint',64);
            $t->tinyInteger('remind_expire')->default(1); $t->tinyInteger('remind_traffic')->default(1);
            $t->unsignedInteger('traffic_cycle')->default(0); $t->bigInteger('last_usage')->default(0);
            $t->bigInteger('last_limit')->default(0); $t->integer('last_plan')->default(0); $t->integer('last_expiry')->nullable();
            $t->integer('created_at'); $t->integer('updated_at');
        });
        if (!$schema->hasTable('v2_telegram_delivery')) $schema->create('v2_telegram_delivery', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedInteger('user_id')->index(); $t->bigInteger('telegram_id');
            $t->string('binding_version',32); $t->string('event_key',64)->unique(); $t->string('kind',16);
            $t->text('payload'); $t->string('status',16)->default('pending')->index(); $t->integer('attempts')->default(0);
            $t->integer('dispatched_at')->nullable(); $t->integer('sent_at')->nullable();
            $t->integer('created_at'); $t->integer('updated_at');
        });
        if (!$schema->hasTable('v2_telegram_member')) $schema->create('v2_telegram_member', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedInteger('user_id')->index();
            $t->bigInteger('chat_id'); $t->bigInteger('telegram_id'); $t->integer('joined_at');
            $t->unique(['chat_id','telegram_id']);
        });
        if (!$schema->hasTable('v2_telegram_action')) $schema->create('v2_telegram_action', function (Blueprint $t) {
            $t->char('event_key',64)->primary(); $t->integer('created_at');
        });
    }

    public static function installed(): bool
    {
        return DB::connection()->getSchemaBuilder()->hasTable('v2_telegram_profile');
    }
}
