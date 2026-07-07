<?php

namespace App\Models\RRHH;

use App\Models\Empleado;
use Illuminate\Database\Eloquent\Model;

class Bonificacion extends Model
{
    protected $connection = 'pgsql';
    protected $table      = 'bonificaciones';
    protected $fillable   = [
        'empleado_id', 'tipo_bonificacion_id', 'estado_id',
        'solicitado_por_id', 'aprobado_por_id',
        'monto', 'descripcion', 'fecha_aplicar',
        'notas_aprobacion', 'aud_usuario',
    ];

    protected $casts = [
        'fecha_aplicar' => 'date',
        'monto'         => 'decimal:2',
    ];

    public function empleado()      { return $this->belongsTo(Empleado::class, 'empleado_id'); }
    public function tipo()          { return $this->belongsTo(TipoBonificacion::class, 'tipo_bonificacion_id'); }
    public function estado()        { return $this->belongsTo(EstadoBonificacion::class, 'estado_id'); }
    public function solicitadoPor() { return $this->belongsTo(Empleado::class, 'solicitado_por_id'); }
    public function aprobadoPor()   { return $this->belongsTo(Empleado::class, 'aprobado_por_id'); }
}
