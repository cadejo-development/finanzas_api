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
     * GET /api/rrhh/dashboard/charts
     * Datos de gráficos de tendencia, ausencias de la semana,
     * aniversarios/cumpleaños e ingresos pendientes.
     * Disponible para jefatura (su equipo) y rrhh_admin (todos).
     */
    public function charts(): JsonResponse
    {
        $ids  = $this->getSubordinadosIds();
        $hoy  = Carbon::today();
        $mesLabels = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

        // ── 1. Tendencia mensual (últimos 6 meses) ────────────────────────────
        $meses       = [];
        $totales     = [];
        $ausenciasPct = [];
        $rotacionPct  = [];
        $totalIds     = max(1, count($ids));

        for ($i = 5; $i >= 0; $i--) {
            $fm     = $hoy->copy()->startOfMonth()->subMonths($i);
            $finMes = $fm->copy()->endOfMonth()->toDateString();

            $meses[] = $mesLabels[$fm->month - 1];

            $totales[] = $ids
                ? DB::connection('pgsql')->table('empleados')
                    ->where('activo', true)->whereIn('id', $ids)
                    ->where('fecha_ingreso', '<=', $finMes)->count()
                : 0;

            $perm = $ids
                ? DB::connection('rrhh')->table('permisos')
                    ->whereIn('empleado_id', $ids)->where('estado', 'aprobado')
                    ->whereYear('fecha', $fm->year)->whereMonth('fecha', $fm->month)->count()
                : 0;

            $incap = $ids
                ? DB::connection('rrhh')->table('incapacidades')
                    ->whereIn('empleado_id', $ids)
                    ->whereYear('fecha_inicio', $fm->year)->whereMonth('fecha_inicio', $fm->month)->count()
                : 0;

            $ausenciasPct[] = round(($perm + $incap) / $totalIds * 100, 2);

            $desvinc = $ids
                ? DB::connection('rrhh')->table('desvinculaciones')
                    ->whereIn('empleado_id', $ids)
                    ->whereYear('fecha_efectiva', $fm->year)->whereMonth('fecha_efectiva', $fm->month)->count()
                : 0;

            $rotacionPct[] = round($desvinc / $totalIds * 100, 2);
        }

        // ── 2. Distribución actual por sucursal (solo admin) ─────────────────
        $porSucursal     = [];
        $porDepartamento = []; // mantenido por compatibilidad
        if ($this->esAdminRrhh() && $ids) {
            $sucs = DB::connection('pgsql')
                ->table('sucursales as s')
                ->join('empleados as e', 'e.sucursal_id', '=', 's.id')
                ->where('e.activo', true)->whereIn('e.id', $ids)
                ->groupBy('s.id', 's.nombre')
                ->orderByRaw('COUNT(e.id) DESC')
                ->limit(10)
                ->selectRaw('s.nombre, COUNT(e.id) as total')
                ->get();

            foreach ($sucs as $s) {
                $porSucursal[] = ['nombre' => $s->nombre, 'total' => (int) $s->total];
            }

            $depts = DB::connection('pgsql')
                ->table('departamentos as d')
                ->join('empleados as e', 'e.departamento_id', '=', 'd.id')
                ->where('e.activo', true)->whereIn('e.id', $ids)->where('d.activo', true)
                ->groupBy('d.id', 'd.nombre')
                ->orderByRaw('COUNT(e.id) DESC')
                ->limit(7)
                ->selectRaw('d.nombre, COUNT(e.id) as total')
                ->get();

            foreach ($depts as $d) {
                $porDepartamento[] = ['nombre' => $d->nombre, 'total' => (int) $d->total];
            }
        }

        // ── 3. Ausencias esta semana ──────────────────────────────────────────
        $lunes   = $hoy->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $domingo = $hoy->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();

        $ausenciasSemana = [];

        if ($ids) {
            $permSemana = DB::connection('rrhh')
                ->table('permisos as p')
                ->leftJoin('tipos_permiso as tp', 'tp.id', '=', 'p.tipo_permiso_id')
                ->whereIn('p.empleado_id', $ids)->where('p.estado', 'aprobado')
                ->whereBetween('p.fecha', [$lunes, $domingo])
                ->selectRaw("p.empleado_id, p.fecha as desde, p.fecha as hasta, p.dias, COALESCE(tp.nombre, 'Permiso') as tipo")
                ->get();

            $vacSemana = DB::connection('rrhh')
                ->table('vacaciones')
                ->whereIn('empleado_id', $ids)->where('estado', 'aprobado')
                ->where('fecha_inicio', '<=', $domingo)->where('fecha_fin', '>=', $lunes)
                ->selectRaw("empleado_id, fecha_inicio as desde, fecha_fin as hasta, dias, 'Vacación' as tipo")
                ->get();

            $incapSemana = DB::connection('rrhh')
                ->table('incapacidades')
                ->whereIn('empleado_id', $ids)
                ->where('fecha_inicio', '<=', $domingo)
                ->where(fn($q) => $q->where('fecha_fin', '>=', $lunes)->orWhereNull('fecha_fin'))
                ->selectRaw("empleado_id, fecha_inicio as desde, fecha_fin as hasta, dias, 'Incapacidad' as tipo")
                ->get();

            $todasAus = $permSemana->merge($vacSemana)->merge($incapSemana);
            $empIds   = $todasAus->pluck('empleado_id')->unique()->filter()->all();

            if ($empIds) {
                $empsMap = DB::connection('pgsql')->table('empleados')
                    ->whereIn('id', $empIds)
                    ->selectRaw("id, TRIM(nombres || ' ' || apellidos) as nombre")
                    ->get()->keyBy('id');

                foreach ($todasAus as $row) {
                    $ausenciasSemana[] = [
                        'empleado_id' => $row->empleado_id,
                        'nombre'      => $empsMap[$row->empleado_id]->nombre ?? "Empleado #{$row->empleado_id}",
                        'tipo'        => $row->tipo,
                        'desde'       => $row->desde,
                        'hasta'       => $row->hasta,
                        'dias'        => (float) ($row->dias ?? 1),
                    ];
                }
            }
        }

        // ── 4. Aniversarios y cumpleaños (próximos 14 días + últimos 3) ────────
        $hace3 = $hoy->copy()->subDays(3);
        $en14  = $hoy->copy()->addDays(14);
        $eventos = [];

        $empleados = $ids
            ? DB::connection('pgsql')->table('empleados')
                ->whereIn('id', $ids)->where('activo', true)->whereNotNull('fecha_ingreso')
                ->selectRaw("id, TRIM(nombres || ' ' || apellidos) as nombre, fecha_ingreso")
                ->get()
            : collect();

        foreach ($empleados as $emp) {
            $fi  = Carbon::parse($emp->fecha_ingreso);
            $anv = $fi->copy()->year($hoy->year);
            if ($anv->between($hace3, $en14)) {
                $eventos[] = [
                    'empleado_id' => $emp->id,
                    'nombre'      => $emp->nombre,
                    'tipo'        => 'Aniversario',
                    'fecha'       => $anv->toDateString(),
                    'anios'       => $hoy->year - $fi->year,
                    'pasado'      => $anv->lt($hoy),
                ];
            }
        }

        if ($ids) {
            $cumples = DB::connection('rrhh')
                ->table('expediente_datos_personales')
                ->whereIn('empleado_id', $ids)->whereNotNull('fecha_nacimiento')
                ->select('empleado_id', 'fecha_nacimiento')->get();

            $empMap = $empleados->keyBy('id');
            foreach ($cumples as $c) {
                $fn  = Carbon::parse($c->fecha_nacimiento);
                $cum = $fn->copy()->year($hoy->year);
                if ($cum->between($hace3, $en14)) {
                    $emp = $empMap[$c->empleado_id] ?? null;
                    $eventos[] = [
                        'empleado_id' => $c->empleado_id,
                        'nombre'      => $emp?->nombre ?? "Empleado #{$c->empleado_id}",
                        'tipo'        => 'Cumpleaños',
                        'fecha'       => $cum->toDateString(),
                        'anios'       => null,
                        'pasado'      => $cum->lt($hoy),
                    ];
                }
            }
        }

        usort($eventos, fn($a, $b) => strcmp($a['fecha'], $b['fecha']));

        // ── 5. Ingresos pendientes (últimos 90 días sin expediente) ───────────
        $hace90    = $hoy->copy()->subDays(90)->toDateString();
        $recientes = $ids
            ? DB::connection('pgsql')->table('empleados')
                ->whereIn('id', $ids)->where('activo', true)->where('fecha_ingreso', '>=', $hace90)
                ->selectRaw("id, TRIM(nombres || ' ' || apellidos) as nombre, fecha_ingreso")
                ->get()
            : collect();

        $conExpediente = [];
        if ($recientes->isNotEmpty()) {
            $conExpediente = DB::connection('rrhh')
                ->table('expediente_datos_personales')
                ->whereIn('empleado_id', $recientes->pluck('id')->all())
                ->pluck('empleado_id')->map(fn($x) => (int) $x)->all();
        }

        $ingresosPendientes = $recientes
            ->filter(fn($e) => !in_array((int) $e->id, $conExpediente))
            ->map(fn($e) => [
                'empleado_id'   => $e->id,
                'nombre'        => $e->nombre,
                'estado'        => 'Incompleto',
                'fecha_ingreso' => $e->fecha_ingreso,
            ])->values()->all();

        return response()->json([
            'success' => true,
            'data'    => [
                'tendencia'           => compact('meses', 'totales', 'ausenciasPct', 'rotacionPct', 'porSucursal', 'porDepartamento'),
                'ausencias_semana'    => $ausenciasSemana,
                'eventos'             => $eventos,
                'ingresos_pendientes' => $ingresosPendientes,
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
        $edadesOrden = ['<20', '20-29', '30-39', '40-49', '50-59', '60-69', '+70'];
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
                $edad <= 69 => '60-69',
                default     => '+70',
            };
            $edades[$rango]++;
        }

        // ── Estudios (nivel más alto por empleado, agrupado en 4 categorías) ──────
        // bachiller = bachillerato
        // grado     = técnico + grado/licenciatura/universitario
        // maestria+ = posgrado + maestría + doctorado
        // otro      = cualquier otro
        $jerarquia = ['maestria_plus' => 5, 'grado' => 3, 'bachillerato' => 2, 'otro' => 1];

        $estudiosRaw = DB::connection('rrhh')
            ->table('expediente_estudios')
            ->whereNotNull('nivel')
            ->select('empleado_id', 'nivel')
            ->get();

        // Un empleado puede tener múltiples entradas; conservar el nivel más alto
        $nivelPorEmpleado = [];
        foreach ($estudiosRaw as $row) {
            $n = strtolower(trim($row->nivel ?? ''));
            $cat = match(true) {
                str_contains($n, 'doctor')
                    || str_contains($n, 'maestr') || str_contains($n, 'master')
                    || str_contains($n, 'postgrado') || str_contains($n, 'posgrado') => 'maestria_plus',
                str_contains($n, 'universit') || str_contains($n, 'grado')
                    || str_contains($n, 'licencia') || str_contains($n, 'ingeni')
                    || str_contains($n, 'tecnico') || str_contains($n, 'técnico')
                    || str_contains($n, 'técnica') || str_contains($n, 'tecnica')    => 'grado',
                str_contains($n, 'bachiller')                                        => 'bachillerato',
                default                                                               => 'otro',
            };
            $actual = $nivelPorEmpleado[$row->empleado_id] ?? null;
            if ($actual === null || ($jerarquia[$cat] ?? 0) > ($jerarquia[$actual] ?? 0)) {
                $nivelPorEmpleado[$row->empleado_id] = $cat;
            }
        }

        $nivelesOrden = ['bachillerato', 'grado', 'maestria_plus', 'otro'];
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
