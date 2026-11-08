<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramProfile extends Model
{
    protected $table = 'v2_telegram_profile';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $dateFormat = 'U';
    protected $guarded = [];
}
