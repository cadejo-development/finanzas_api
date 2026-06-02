<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class IngresoQrToken extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'ingreso_qr_tokens';

    protected $fillable = [
        'token',
        'generado_por_user_id',
        'expires_at',
        'usado_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'usado_at'   => 'datetime',
    ];

    public function isVigente(): bool
    {
        return $this->expires_at->isFuture();
    }

    public function registros()
    {
        return $this->hasMany(IngresoQrRegistro::class, 'qr_token_id');
    }
}
