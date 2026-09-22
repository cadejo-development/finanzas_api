<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class CambioSalarial extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'cambios_salariales';

    protected $fillable = [
        'empleado_id', 'solicitado_por_id', 'tipo_aumento_id',
        'salario_anterior', 'salario_nuevo', 'porcentaje',
        'fecha_efectiva', 'justificacion', 'motivo_rechazo', 'estado',
        'aprobacion_token', 'aud_usuario', 'creado_por',
        'documento_ruta', 'documento_nombre', 'documento_mime',
        'test_notif_para',
    ];

    protected $casts = [
        'salario_anterior' => 'decimal:2',
        'salario_nuevo'    => 'decimal:2',
        'porcentaje'       => 'decimal:2',
        'fecha_efectiva'   => 'date',
    ];

    public function tipoAumento()
    {
        return $this->belongsTo(TipoAumentoSalarial::class, 'tipo_aumento_id');
    }
}
