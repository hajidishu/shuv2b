<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\EmailAddress;
use App\Services\EmailIdentity;

class User extends Model
{
    public function save(array $options = [])
    {
        if ($this->exists && !$this->isDirty('email')) return parent::save($options);
        return EmailIdentity::writing(function () use ($options) {
            return $this->getConnection()->transaction(function () use ($options) {
                $this->email = EmailAddress::normalize($this->email, true);
                EmailIdentity::assertAvailable($this->email, $this->exists ? (int)$this->id : null);
                $saved = parent::save($options);
                if ($saved) EmailIdentity::synchronize($this);
                return $saved;
            });
        });
    }

    protected static function boot()
    {
        parent::boot();
        static::deleted(function (User $user) { EmailIdentity::remove((int)$user->id); });
    }

    protected $table = 'v2_user';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
}
