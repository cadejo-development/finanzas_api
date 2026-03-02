<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PermissionRole extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'permission_role';
    protected $fillable = [
        'permission_id', 'role_id', 'aud_usuario'
    ];
}
