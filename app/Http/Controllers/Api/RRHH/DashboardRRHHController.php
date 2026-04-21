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
        // Contar TODOS los empleados activos; los que no tienen expediente
        // o tienen género null/vacío van a "sin_definir".
        // Los que pusieron explícitamente "otro" van a su propia categoría.
        $totalActivos = DB::connection('pgsql')
            ->table('empleados')
            ->where('activo', true)
            ->count();

        $generoExpediente = DB::connection('rrhh')
            ->table('expediente_datos_personales')
            ->selectRaw("LOWER(TRIM(genero)) as genero, COUNT(*) as total")
            ->groupByRaw("LOWER(TRIM(genero))")
            ->get();

        $genero = [
            'masculino'   => 0,
            'femenino'    => 0,
            'otro'        => 0,
            'sin_definir' => 0,
        ];
        $conExpediente = 0;
        foreach ($generoExpediente as $row) {
            if ($row->genero === 'masculino') {
                $genero['masculino'] += (int) $row->total;
                $conExpediente += (int) $row->total;
            } elseif ($row->genero === 'femenino') {
                $genero['femenino'] += (int) $row->total;
                $conExpediente += (int) $row->total;
            } elseif (!is_null($row->genero) && $row->genero !== '') {
                // Cualquier otro valor explícito ("otro", "no_binario", etc.) → Otro
                $genero['otro'] += (int) $row->total;
                $conExpediente += (int) $row->total;
            }
            // null / vacío: no suma a conExpediente, cae en sin_definir
        }
        // Sin definir = empleados activos sin expediente o con género null/vacío
        $genero['sin_definir'] = max(0, $totalActivos - $conExpediente);

        // ── Edades ────────────────────────────────────────────────────────────
        // Se lee fecha_nacimiento del expediente. Agrupamos en PHP para evitar
        // dependencias de GROUP BY alias en distintos modos de PostgreSQL.
        $edadesOrden = ['<20', '20-29', '30-39', '40-49', '50-59', '60-65', '+65'];
        $edades = array_fill_keys($edadesOrden, 0);

        $nacimientos = DB::connection('rrhh')
            ->table('expediente_datos_personales')
            ->whereNotNull('fecha_nacimiento')
            ->pluck('fecha_nacimiento');

        foreach ($nacimientos as $fn) {
            $edad = (int) Carbon::parse($fn)->age;
            $rango = match(true) {
                $edad < 20  => '<20',
                $edad <= 29 => '20-29',
                $edad <= 39 => '30-39',
                $edad <= 49 => '40-49',
                $edad <= 59 => '50-59',
                $edad <= 65 => '60-65',
                default     => '+65',
            };
            $edades[$rango]++;
        }

        // ── Estudios (nivel más alto por empleado) ────────────────────────────
        // Tomamos el nivel más alto registrado por empleado.
        $jerarquia = ['doctorado' => 6, 'maestria' => 5, 'posgrado' => 5, 'grado' => 4,
                      'universitario' => 4, 'tecnico' => 3, 'bachillerato' => 2, 'otro' => 1];

        $estudiosRaw = DB::connection('rrhh')
            ->table('expediente_estudios')
            ->whereNotNull('nivel')
            ->select('empleado_id', 'nivel')
            ->get();

        // Un empleado puede tener múltiples entradas; conservar el nivel más alto
        $nivelPorEmpleado = [];
        foreach ($estudiosRaw as $row) {
            $n = strtolower(trim($row->nivel ?? ''));
            $n = match(true) {
                str_contains($n, 'doctor')                                                     => 'doctorado',
                str_contains($n, 'maestr') || str_contains($n, 'master')                      => 'maestria',
                str_contains($n, 'postgrado') || str_contains($n, 'posgrado')                 => 'posgrado',
                str_contains($n, 'universit') || str_contains($n, 'grado')
                    || str_contains($n, 'licencia') || str_contains($n, 'ingeni')              => 'grado',
                str_contains($n, 'tecnico') || str_contains($n, 'técnico')
                    || str_contains($n, 'técnica') || str_contains($n, 'tecnica')             => 'tecnico',
                str_contains($n, 'bachiller')                                                  => 'bachillerato',
                default                                                                        => 'otro',
            };
            $actual = $nivelPorEmpleado[$row->empleado_id] ?? null;
            if ($actual === null || ($jerarquia[$n] ?? 0) > ($jerarquia[$actual] ?? 0)) {
                $nivelPorEmpleado[$row->empleado_id] = $n;
            }
        }

        $nivelesOrden = ['bachillerato', 'tecnico', 'grado', 'posgrado', 'maestria', 'doctorado', 'otro'];
        $estudios = array_fill_keys($nivelesOrden, 0);
        foreach ($nivelPorEmpleado as $nivel) {
            if (isset($estudios[$nivel])) $estudios[$nivel]++;
        }

        // ── Antigüedad laboral ────────────────────────────────────────────────
        // Se lee fecha_ingreso de empleados activos. Agrupamos en PHP.
        $antiguedadOrden = ['<1', '1-3', '3-5', '5-10', '+10'];
        $antiguedad = array_fill_keys($antiguedadOrden, 0);

        $ingresos = DB::connection('pgsql')
            ->table('empleados')
            ->where('activo', true)
            ->whereNotNull('fecha_ingreso')
            ->pluck('fecha_ingreso');

        foreach ($ingresos as $fi) {
            $anios = (int) Carbon::parse($fi)->diffInYears(now());
            $rango = match(true) {
                $anios < 1  => '<1',
                $anios <= 3 => '1-3',
                $anios <= 5 => '3-5',
                $anios <= 10 => '5-10',
                default     => '+10',
            };
            $antiguedad[$rango]++;
        }

        return response()->json([
            'success' => true,
            'data'    => compact('genero', 'edades', 'estudios', 'antiguedad'),
        ]);
    }
}
