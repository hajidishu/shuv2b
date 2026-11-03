<?php

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

class ExternalNodeSchema
{
    public function ensure(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        if ($schema->hasTable('v2_server_external')) {
            if (!$schema->hasColumn('v2_server_external', 'group_sort')) $schema->table('v2_server_external', function (Blueprint $table) {
                $table->text('group_sort')->nullable();
            });
            return;
        }
        $schema->create('v2_server_external', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->text('group_id');
            $table->text('group_sort')->nullable();
            $table->text('config');
            $table->unsignedTinyInteger('show')->default(1);
            $table->integer('sort')->default(0)->index();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }
}
