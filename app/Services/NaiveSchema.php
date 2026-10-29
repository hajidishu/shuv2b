<?php

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

class NaiveSchema
{
    public function ensure(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        if (!$schema->hasTable('v2_server_naive')) $schema->create('v2_server_naive', function (Blueprint $t) {
            $t->increments('id'); $t->string('group_id'); $t->string('route_id')->nullable();
            $t->string('name'); $t->integer('parent_id')->nullable(); $t->string('host');
            $t->string('listen_ip')->default('0.0.0.0'); $t->string('port', 11); $t->integer('server_port');
            $t->string('tags')->nullable(); $t->string('rate', 11); $t->tinyInteger('show')->default(0);
            $t->integer('sort')->nullable(); $t->string('network', 11)->default('tcp');
            $t->tinyInteger('tls')->default(1); $t->text('tls_settings')->nullable();
            $t->string('congestion_control', 16)->default('bbr');
            $t->integer('created_at'); $t->integer('updated_at');
        });
        foreach (['id','group_id','route_id','name','parent_id','host','listen_ip','port','server_port','tags','rate','show','sort','network','tls','tls_settings','congestion_control','created_at','updated_at'] as $column) {
            if (!$schema->hasColumn('v2_server_naive', $column)) throw new \RuntimeException('Naive 节点表缺少字段：' . $column);
        }
    }
}
