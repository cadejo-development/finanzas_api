<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoleUser extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'role_user';
    protected $fillable = [
        'user_id', 'role_id', 'aud_usuario'
    ];
}
