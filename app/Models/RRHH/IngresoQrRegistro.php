<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class IngresoQrRegistro extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'ingreso_qr_registros';

    protected $fillable = [
        'qr_token_id',
        'nombres',
        'apellidos',
        'fecha_nacimiento',
        'genero',
        'estado_civil',
        'lugar_nacimiento',
        'telefono',
        'email',
        'direccion',
        'dui',
        'nit',
        'afp_nombre',
        'afp_numero',
        'isss_numero',
        'ultimo_nivel_academico',
        'titulo_academico',
        'institucion_academica',
        'graduado',
        'ip_address',
        'origen',
    ];

    protected $casts = [
        'fecha_nacimiento' => 'date',
        'graduado'         => 'boolean',
    ];

    public function token()
    {
        return $this->belongsTo(IngresoQrToken::class, 'qr_token_id');
    }
}
