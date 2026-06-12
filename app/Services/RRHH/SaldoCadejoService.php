<?php

namespace App\Services\RRHH;

use App\Models\RRHH\SaldoCadejo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SaldoCadejoService
{
    public const DIAS_POR_ANIO      = 3;
    public const MESES_MIN_NUEVO    = 3;   // meses consecutivos para nuevo ingreso
    public const ANTICIPACION_DIAS  = 5;   // días hábiles mínimos de anticipación

    /**
     * Obtiene o crea el saldo de Días Cadejo para el empleado en el año dado.
     * Devuelve null si el empleado no es elegible todavía.
     */
    public static function obtenerOCrear(int $empleadoId, int $anio, int $mesesAntiguedad): ?SaldoCadejo
    {
        $saldo = SaldoCadejo::where('empleado_id', $empleadoId)
            ->where('anio', $anio)
            ->first();

        if ($saldo) {
            return $saldo;
        }

        // Nuevo ingreso: debe tener al menos 3 meses consecutivos
        if ($mesesAntiguedad < self::MESES_MIN_NUEVO) {
            return null;
        }

        return SaldoCadejo::create([
            'empleado_id'      => $empleadoId,
            'anio'             => $anio,
            'dias_disponibles' => self::DIAS_POR_ANIO,
            'dias_usados'      => 0,
            'aud_usuario'      => Auth::user()?->email ?? 'sistema',
        ]);
    }

    /**
     * Valida si hay saldo suficiente para los días solicitados.
     * Retorna un string con el mensaje de error, o null si está bien.
     */
    public static function validar(int $empleadoId, float $diasSolicitados, int $anio, int $mesesAntiguedad): ?string
    {
        $saldo = self::obtenerOCrear($empleadoId, $anio, $mesesAntiguedad);

        if ($saldo === null) {
            return "El colaborador no ha cumplido los {$mesesAntiguedad} meses mínimos para usar Días Cadejo (requiere " . self::MESES_MIN_NUEVO . " meses consecutivos).";
        }

        $restantes = (float) $saldo->dias_disponibles - (float) $saldo->dias_usados;

        if ($diasSolicitados > $restantes) {
            $usados = number_format($saldo->dias_usados, 1);
            $disp   = number_format($saldo->dias_disponibles, 1);
            return "No hay saldo de Días Cadejo suficiente. Disponibles: {$restantes} de {$disp} día(s) (usados: {$usados}).";
        }

        return null;
    }

    /**
     * Descuenta días del saldo al aprobar un permiso Cadejo.
     */
    public static function descontar(int $empleadoId, float $dias, int $anio): void
    {
        SaldoCadejo::where('empleado_id', $empleadoId)
            ->where('anio', $anio)
            ->increment('dias_usados', $dias);
    }

    /**
     * Devuelve días al saldo al rechazar o eliminar un permiso Cadejo aprobado.
     */
    public static function devolver(int $empleadoId, float $dias, int $anio): void
    {
        SaldoCadejo::where('empleado_id', $empleadoId)
            ->where('anio', $anio)
            ->decrement('dias_usados', $dias);
    }

    /**
     * Saldos de Días Cadejo para una lista de empleados en el año actual.
     */
    public static function saldosPorEmpleados(array $empleadoIds, int $anio): array
    {
        $registros = SaldoCadejo::whereIn('empleado_id', $empleadoIds)
            ->where('anio', $anio)
            ->get()
            ->keyBy('empleado_id');

        $resultado = [];
        foreach ($empleadoIds as $id) {
            if ($registros->has($id)) {
                $s = $registros[$id];
                $resultado[] = [
                    'empleado_id'      => $id,
                    'anio'             => $anio,
                    'dias_disponibles' => (float) $s->dias_disponibles,
                    'dias_usados'      => (float) $s->dias_usados,
                    'dias_restantes'   => max((float) $s->dias_disponibles - (float) $s->dias_usados, 0),
                ];
            } else {
                // Sin registro aún: pueden tener 3 días disponibles si califican
                $resultado[] = [
                    'empleado_id'      => $id,
                    'anio'             => $anio,
                    'dias_disponibles' => self::DIAS_POR_ANIO,
                    'dias_usados'      => 0,
                    'dias_restantes'   => self::DIAS_POR_ANIO,
                ];
            }
        }

        return $resultado;
    }
}
