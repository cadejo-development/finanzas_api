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
use Carbon\Carbon;

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

    /**
     * GET /api/rrhh/dashboard/demograficos
     * Estadísticas demográficas del personal — solo rrhh_admin.
     */
    public function demograficos(): JsonResponse
    {
        if (!$this->esAdminRrhh()) {
            return response()->json(['success' => false, 'message' => 'No autorizado.'], 403);
        }

        // ── Género ────────────────────────────────────────────────────────────
        $generoRaw = DB::connection('rrhh')
            ->table('expediente_datos_personales')
            ->selectRaw("COALESCE(genero, 'no_especificado') as genero, COUNT(*) as total")
            ->groupBy('genero')
            ->get();

        $genero = [
            'masculino'       => 0,
            'femenino'        => 0,
            'no_especificado' => 0,
        ];
        foreach ($generoRaw as $row) {
            $key = in_array($row->genero, array_keys($genero)) ? $row->genero : 'no_especificado';
            $genero[$key] += (int) $row->total;
        }

        // ── Edades ────────────────────────────────────────────────────────────
        $edadesRaw = DB::connection('rrhh')
            ->table('expediente_datos_personales')
            ->whereNotNull('fecha_nacimiento')
            ->selectRaw("
                CASE
                    WHEN EXTRACT(YEAR FROM AGE(fecha_nacimiento)) < 20         THEN '<20'
                    WHEN EXTRACT(YEAR FROM AGE(fecha_nacimiento)) BETWEEN 20 AND 29 THEN '20-29'
                    WHEN EXTRACT(YEAR FROM AGE(fecha_nacimiento)) BETWEEN 30 AND 39 THEN '30-39'
                    WHEN EXTRACT(YEAR FROM AGE(fecha_nacimiento)) BETWEEN 40 AND 49 THEN '40-49'
                    WHEN EXTRACT(YEAR FROM AGE(fecha_nacimiento)) BETWEEN 50 AND 59 THEN '50-59'
                    WHEN EXTRACT(YEAR FROM AGE(fecha_nacimiento)) BETWEEN 60 AND 65 THEN '60-65'
                    ELSE '+65'
                END as rango,
                COUNT(*) as total
            ")
            ->groupByRaw('rango')
            ->get();

        $edadesOrden = ['<20', '20-29', '30-39', '40-49', '50-59', '60-65', '+65'];
        $edades = array_fill_keys($edadesOrden, 0);
        foreach ($edadesRaw as $row) {
            if (isset($edades[$row->rango])) {
                $edades[$row->rango] = (int) $row->total;
            }
        }

        // ── Estudios (nivel más alto por empleado) ────────────────────────────
        // Usamos COUNT DISTINCT de empleados por nivel (un empleado puede tener
        // varios registros; contamos cada uno una sola vez).
        $estudiosRaw = DB::connection('rrhh')
            ->table('expediente_estudios')
            ->whereNotNull('nivel')
            ->selectRaw("nivel, COUNT(DISTINCT empleado_id) as total")
            ->groupBy('nivel')
            ->get();

        $nivelesOrden = ['bachillerato', 'tecnico', 'grado', 'maestria', 'doctorado', 'otro'];
        $estudios = array_fill_keys($nivelesOrden, 0);
        foreach ($estudiosRaw as $row) {
            $nivel = strtolower(trim($row->nivel ?? ''));
            // Normalizar variaciones comunes
            $nivel = match(true) {
                str_contains($nivel, 'maestr') || str_contains($nivel, 'master') => 'maestria',
                str_contains($nivel, 'doctor')                                   => 'doctorado',
                str_contains($nivel, 'grado') || str_contains($nivel, 'licencia') || str_contains($nivel, 'ingeni') => 'grado',
                str_contains($nivel, 'tecnico') || str_contains($nivel, 'técnico') => 'tecnico',
                str_contains($nivel, 'bachiller')                                => 'bachillerato',
                default                                                          => 'otro',
            };
            $estudios[$nivel] += (int) $row->total;
        }

        // ── Antigüedad laboral ────────────────────────────────────────────────
        $antiguedadRaw = DB::connection('pgsql')
            ->table('empleados')
            ->where('activo', true)
            ->whereNotNull('fecha_ingreso')
            ->selectRaw("
                CASE
                    WHEN EXTRACT(YEAR FROM AGE(fecha_ingreso::date)) < 1              THEN '<1'
                    WHEN EXTRACT(YEAR FROM AGE(fecha_ingreso::date)) BETWEEN 1 AND 3  THEN '1-3'
                    WHEN EXTRACT(YEAR FROM AGE(fecha_ingreso::date)) BETWEEN 3 AND 5  THEN '3-5'
                    WHEN EXTRACT(YEAR FROM AGE(fecha_ingreso::date)) BETWEEN 5 AND 10 THEN '5-10'
                    ELSE '+10'
                END as rango,
                COUNT(*) as total
            ")
            ->groupByRaw('rango')
            ->get();

        $antiguedadOrden = ['<1', '1-3', '3-5', '5-10', '+10'];
        $antiguedad = array_fill_keys($antiguedadOrden, 0);
        foreach ($antiguedadRaw as $row) {
            if (isset($antiguedad[$row->rango])) {
                $antiguedad[$row->rango] = (int) $row->total;
            }
        }

        return response()->json([
            'success' => true,
            'data'    => compact('genero', 'edades', 'estudios', 'antiguedad'),
        ]);
    }
}
