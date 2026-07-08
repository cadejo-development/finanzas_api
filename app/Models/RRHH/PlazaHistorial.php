<?php

namespace App\Models\RRHH;

use App\Models\Empleado;
use Illuminate\Database\Eloquent\Model;

class PlazaHistorial extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'plaza_historial';

    protected $fillable = [
        'plaza_id', 'empleado_id',
        'motivo_entrada', 'fecha_inicio',
        'fecha_fin', 'motivo_salida',
        'notas', 'aud_usuario',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin'    => 'date',
    ];

    public function plaza()
    {
        return $this->belongsTo(Plaza::class);
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }

    public function esCurrent(): bool
    {
        return is_null($this->fecha_fin);
    }
}
