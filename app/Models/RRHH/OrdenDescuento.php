<?php

namespace App\Models\RRHH;

use App\Models\Empleado;
use Illuminate\Database\Eloquent\Model;

class OrdenDescuento extends Model
{
    protected $connection = 'pgsql';
    protected $table      = 'ordenes_descuento';
    protected $fillable   = [
        'empleado_id', 'acreedor_id', 'estado_id',
        'monto_q1', 'monto_q2', 'referencia', 'fecha_inicio', 'fecha_fin',
        'notas', 'aud_usuario',
    ];

    protected $casts = [
        'fecha_inicio' => 'date:Y-m-d',
        'fecha_fin'    => 'date:Y-m-d',
        'monto_q1'     => 'decimal:2',
        'monto_q2'     => 'decimal:2',
    ];

    public function empleado()  { return $this->belongsTo(Empleado::class); }
    public function acreedor()  { return $this->belongsTo(Acreedor::class); }
    public function estado()    { return $this->belongsTo(EstadoOrdenDescuento::class, 'estado_id'); }
}
