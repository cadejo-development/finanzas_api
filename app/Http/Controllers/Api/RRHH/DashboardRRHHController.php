<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\Amonestacion;
use App\Models\RRHH\Incapacidad;
use App\Models\RRHH\Permiso;
use App\Models\RRHH\SaldoVacaciones;
use App\Models\RRHH\Vacacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardRRHHController extends RRHHBaseController
{
    /**
     * KPIs del dashboard RRHH para el jefe autenticado.
     * GET /api/rrhh/dashboard
     */
    public function resumen(): JsonResponse
    {
        $subordinadosIds = $this->getSubordinadosIds();
        $anioActual      = now()->year;
        $mesActual       = now()->month;

        // Empleados supervisados
        $totalEmpleados = count($subordinadosIds);

        // Permisos pendientes
        $permisosPendientes = Permiso::whereIn('empleado_id', $subordinadosIds)
            ->where('estado', 'pendiente')
            ->count();

        // Vacaciones pendientes
        $vacacionesPendientes = Vacacion::whereIn('empleado_id', $subordinadosIds)
            ->where('estado', 'pendiente')
            ->count();

        // Incapacidades este mes
        $incapacidadesMes = Incapacidad::whereIn('empleado_id', $subordinadosIds)
            ->whereYear('fecha_inicio', $anioActual)
            ->whereMonth('fecha_inicio', $mesActual)
            ->count();

        // Amonestaciones este mes
        $amonestacionesMes = Amonestacion::whereIn('empleado_id', $subordinadosIds)
            ->whereYear('fecha_amonestacion', $anioActual)
            ->whereMonth('fecha_amonestacion', $mesActual)
            ->count();

        // Saldos de vacaciones del equipo
        $saldos = SaldoVacaciones::whereIn('empleado_id', $subordinadosIds)
            ->where('anio', $anioActual)
            ->get(['empleado_id', 'dias_disponibles', 'dias_usados', 'dias_acumulados']);

        // Enriquecer saldos con nombres
        $saldosEnriquecidos = [];
        if ($subordinadosIds) {
            $empleados = DB::connection('pgsql')
                ->table('empleados as e')
                ->join('cargos as c', 'e.cargo_id', '=', 'c.id')
                ->whereIn('e.id', $subordinadosIds)
                ->select('e.id', 'e.nombres', 'e.apellidos', 'e.created_at as fecha_ingreso', 'c.nombre as cargo')
                ->get()
                ->keyBy('id');

            $saldosEnriquecidos = $saldos->map(function ($s) use ($empleados) {
                $emp = $empleados[$s->empleado_id] ?? null;
                return [
                    'empleado_id'      => $s->empleado_id,
                    'empleado_nombre'  => $emp ? trim($emp->nombres . ' ' . $emp->apellidos) : null,
                    'cargo'            => $emp?->cargo,
                    'fecha_ingreso'    => $emp?->fecha_ingreso,
                    'dias_disponibles' => $s->dias_disponibles,
                    'dias_usados'      => $s->dias_usados,
                    'dias_acumulados'  => $s->dias_acumulados,
                    'dias_totales'     => $s->dias_disponibles + $s->dias_acumulados,
                ];
            })->values();
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'es_admin'               => $this->esAdminRrhh(),
                'total_empleados'        => $totalEmpleados,
                'permisos_pendientes'    => $permisosPendientes,
                'vacaciones_pendientes'  => $vacacionesPendientes,
                'incapacidades_mes'      => $incapacidadesMes,
                'amonestaciones_mes'     => $amonestacionesMes,
                'saldos_vacaciones'      => $saldosEnriquecidos,
            ],
        ]);
    }
}
