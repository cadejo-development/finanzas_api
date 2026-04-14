<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HorarioEmpleado extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'horarios_empleado';

    protected $fillable = [
        'empleado_id',
        'fecha',
        'hora_inicio',
        'hora_fin',
        'tipo',
        'notas',
        'aud_usuario',
    ];

    protected $casts = [
        'fecha' => 'date:Y-m-d',
    ];

    // Tipos válidos
    public const TIPOS = ['normal', 'libre', 'vacacion', 'dia_cadejo', 'incapacidad'];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }

    /**
     * Horas trabajadas del turno (null si no es tipo normal o faltan datos).
     */
    public function getHorasTrabajadas(): ?float
    {
        if ($this->tipo !== 'normal' || !$this->hora_inicio || !$this->hora_fin) {
            return null;
        }
        [$h1, $m1] = explode(':', $this->hora_inicio);
        [$h2, $m2] = explode(':', $this->hora_fin);
        $mins = ((int)$h2 * 60 + (int)$m2) - ((int)$h1 * 60 + (int)$m1);
        // turno nocturno que cruza medianoche
        if ($mins < 0) $mins += 24 * 60;
        return round($mins / 60, 2);
    }
}
