<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerExternal extends Model
{
    protected $table = 'v2_server_external';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = ['group_id' => 'array', 'group_sort' => 'array', 'config' => 'array', 'show' => 'integer', 'sort' => 'integer', 'created_at' => 'timestamp', 'updated_at' => 'timestamp'];
}
