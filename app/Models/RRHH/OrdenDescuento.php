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
        'monto', 'referencia', 'fecha_inicio', 'fecha_fin',
        'notas', 'aud_usuario',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin'    => 'date',
        'monto'        => 'decimal:2',
    ];

    public function empleado()  { return $this->belongsTo(Empleado::class); }
    public function acreedor()  { return $this->belongsTo(Acreedor::class); }
    public function estado()    { return $this->belongsTo(EstadoOrdenDescuento::class, 'estado_id'); }
}
