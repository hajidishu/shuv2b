<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramDelivery extends Model
{
    protected $table = 'v2_telegram_delivery';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = ['payload'=>'array'];
}
