<?php

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

class MieruSchema
{
    public function ensure(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        if (!$schema->hasTable('v2_server_mieru')) $schema->create('v2_server_mieru', function (Blueprint $t) {
            $t->increments('id'); $t->text('group_id'); $t->text('route_id')->nullable();
            $t->string('name'); $t->integer('parent_id')->nullable(); $t->string('host');
            $t->string('listen_ip')->default('0.0.0.0'); $t->string('port', 11); $t->integer('server_port');
            $t->text('tags')->nullable(); $t->string('rate', 11); $t->tinyInteger('show')->default(0);
            $t->integer('sort')->nullable(); $t->string('network', 11)->default('tcp'); $t->text('network_settings')->nullable();
            $t->integer('created_at'); $t->integer('updated_at');
        });
        foreach (['id','group_id','route_id','name','parent_id','host','listen_ip','port','server_port','tags','rate','show','sort','network','network_settings','created_at','updated_at'] as $column) {
            if (!$schema->hasColumn('v2_server_mieru', $column)) throw new \RuntimeException('Mieru 节点表缺少字段：' . $column);
        }
    }
}
