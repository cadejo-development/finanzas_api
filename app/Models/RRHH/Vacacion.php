<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class Vacacion extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'vacaciones';

    protected $fillable = [
        'empleado_id', 'jefe_id',
        'fecha_inicio', 'fecha_fin', 'dias',
        'estado', 'observaciones', 'aud_usuario', 'creado_por',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin'    => 'date',
        'dias'         => 'decimal:1',
    ];

    protected static function booted(): void
    {
        // Vacación creada directamente como aprobada (ej: admin aprueba para un subordinado)
        static::created(function (self $v) {
            if ($v->estado === 'aprobado') {
                self::ajustarSaldo($v->empleado_id, (float) $v->dias);
            }
        });

        // Transición a aprobado desde cualquier otro estado, por cualquier código
        static::updated(function (self $v) {
            if ($v->wasChanged('estado')
                && $v->estado === 'aprobado'
                && $v->getOriginal('estado') !== 'aprobado') {
                self::ajustarSaldo($v->empleado_id, (float) $v->dias);
            }
        });
    }

    private static function ajustarSaldo(int $empleadoId, float $dias): void
    {
        $saldo = SaldoVacaciones::firstOrCreate(
            ['empleado_id' => $empleadoId, 'anio' => now()->year],
            ['dias_disponibles' => 15, 'dias_usados' => 0, 'dias_acumulados' => 0]
        );
        $saldo->increment('dias_usados', $dias);
    }
}
