<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerNaive extends Model
{
    protected $table = 'v2_server_naive';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp', 'updated_at' => 'timestamp',
        'group_id' => 'array', 'route_id' => 'array', 'tags' => 'array',
        'tls_settings' => 'array',
    ];
}
