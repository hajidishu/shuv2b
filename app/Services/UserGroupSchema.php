<?php
namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

class UserGroupSchema
{
    public function ensure(): void
    {
        $schema=DB::connection()->getSchemaBuilder();
        if (!$schema->hasColumn('v2_user','group_mode')) $schema->table('v2_user',function (Blueprint $table) {
            $table->unsignedTinyInteger('group_mode')->default(0);
        });
    }
}
