<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\AusenciaInjustificada;
use App\Models\RRHH\Incapacidad;
use App\Models\RRHH\Permiso;
use App\Models\RRHH\Vacacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Reporte quincenal de días trabajados / no trabajados / propinas.
 *
 * Días NO trabajados para la quincena seleccionada (descuentos):
 *   - Permisos sin goce (categoria = sin_goce)
 *   - Incapacidades privadas
 *   - Incapacidades ISSS con más de 3 días (los primeros 3 no descuentan)
 *   - Ausencias injustificadas
 *   - Vacaciones
 *   - Suspensiones (amonestaciones con días de suspensión)
 *
 * Días propinas = TODOS los días no trabajados de la quincena ANTERIOR
 * (sin importar el tipo, incluyendo permisos con goce, ISSS, etc.).
 */
class ReportesRRHHController extends RRHHBaseController
{
    public function quincena(Request $request): JsonResponse
    {
        try {
        $subordinadosIds = $this->getSubordinadosIds();

        $anio       = (int) ($request->query('anio', now()->year));
        $mes        = (int) ($request->query('mes', now()->month));
        $quincena   = (int) ($request->query('quincena', now()->day <= 15 ? 1 : 2));
        $sucursalId  = $request->query('sucursal_id');
        $deptoId     = $request->query('departamento_id');

        // Calcular rango de fechas de la quincena actual
        [$desdeAct, $hastaAct] = $this->rangoQuincena($anio, $mes, $quincena);

        // Quincena anterior
        [$prevAnio, $prevMes, $prevQ] = $this->quincenaAnterior($anio, $mes, $quincena);
        [$desdePrev, $hastaPrev] = $this->rangoQuincena($prevAnio, $prevMes, $prevQ);

        // Días hábiles de la quincena actual y anterior
        $diasQuincena     = $desdeAct->diffInDays($hastaAct) + 1;
        $diasQuincenaPrev = $desdePrev->diffInDays($hastaPrev) + 1;

        // --- Cargar empleados filtrados ---
        $empleados = $this->getEmpleadosFiltrados($subordinadosIds, $sucursalId, $deptoId);

        // --- Cargar eventos ambas quincenas ---
        $empIds = collect($empleados)->pluck('id')->toArray();

        $permisosAct  = $this->getPermisos($empIds, $desdeAct, $hastaAct);
        $permisosPrev = $this->getPermisos($empIds, $desdePrev, $hastaPrev);

        $incapAct  = $this->getIncapacidades($empIds, $desdeAct, $hastaAct);
        $incapPrev = $this->getIncapacidades($empIds, $desdePrev, $hastaPrev);

        $vacacAct  = $this->getVacaciones($empIds, $desdeAct, $hastaAct);
        $vacacPrev = $this->getVacaciones($empIds, $desdePrev, $hastaPrev);

        $ausAct   = $this->getAusencias($empIds, $desdeAct, $hastaAct);
        $ausPrev  = $this->getAusencias($empIds, $desdePrev, $hastaPrev);

        // --- Horas nocturnas (horarios con hora_fin > 19:00) ---
        $horasNocturnasMap = $this->getHorasNocturnas($empIds, $desdeAct, $hastaAct);

        // --- Mapa de tipo de sucursal por empleado (fallback si el join devolvió null) ---
        $sucursalTipoMap = $this->getSucursalTiposMap(
            array_unique(array_column($empleados, 'sucursal_id'))
        );

        // --- Construir report por empleado ---
        $reporte = [];

        foreach ($empleados as $emp) {
            $eid = $emp['id'];

            // Días NO trabajados (actuales — descuentan)
            $diasNoTrabajadosAct = $this->calcDiasNoTrabajadosDescuentos(
                $eid, $permisosAct, $incapAct, $vacacAct, $ausAct, $desdeAct, $hastaAct
            );

            // Tipo de sucursal: usar el del JOIN; si es null, buscar por sucursal_id
            $sucursalTipo = $emp['sucursal_tipo']
                ?? ($sucursalTipoMap[$emp['sucursal_id'] ?? 0] ?? null);

            // Días propinas: solo empleados de sucursales operativas (restaurante)
            $esRestaurante = $sucursalTipo === 'operativa';

            if ($esRestaurante) {
                $eventosPrev = $this->calcDiasTodosEventos(
                    $eid, $permisosPrev, $incapPrev, $vacacPrev, $ausPrev, $desdePrev, $hastaPrev
                );
                $diasPropinas = max(0, $diasQuincenaPrev - $eventosPrev['total']);
            } else {
                $diasPropinas = 0;
            }

            $reporte[] = [
                'empleado_id'        => $eid,
                'codigo'             => $emp['codigo'] ?? null,
                'nombre'             => $emp['nombre'] ?? ($emp['nombre_completo'] ?? '—'),
                'sucursal'           => $emp['sucursal'] ?? null,
                'sucursal_tipo'      => $sucursalTipo,
                'departamento'       => $emp['departamento'] ?? null,
                'cargo'              => $emp['cargo'] ?? null,
                'dias_quincena'      => $diasQuincena,
                'dias_no_trabajados' => $diasNoTrabajadosAct['total'],
                'dias_trabajados'    => max(0, $diasQuincena - $diasNoTrabajadosAct['total']),
                'detalles'           => $diasNoTrabajadosAct['detalles'],
                'dias_propinas'      => $diasPropinas,
                'horas_nocturnas'    => round($horasNocturnasMap[$eid] ?? 0, 2),
            ];
        }

        return response()->json([
            'success'   => true,
            'data'      => $reporte,
            'meta' => [
                'anio'        => $anio,
                'mes'         => $mes,
                'quincena'    => $quincena,
                'desde'       => $desdeAct->toDateString(),
                'hasta'       => $hastaAct->toDateString(),
                'prev_desde'  => $desdePrev->toDateString(),
                'prev_hasta'  => $hastaPrev->toDateString(),
            ],
        ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
            ], 500);
        }
    }

    // ─── Helpers de rango ────────────────────────────────────────────────────

    private function rangoQuincena(int $anio, int $mes, int $q): array
    {
        if ($q === 1) {
            $desde = Carbon::create($anio, $mes, 1);
            $hasta = Carbon::create($anio, $mes, 15);
        } else {
            $desde = Carbon::create($anio, $mes, 16);
            $hasta = Carbon::create($anio, $mes)->endOfMonth()->startOfDay();
        }
        return [$desde, $hasta];
    }

    private function quincenaAnterior(int $anio, int $mes, int $q): array
    {
        if ($q === 2) {
            return [$anio, $mes, 1];
        }
        $fecha = Carbon::create($anio, $mes, 1)->subMonth();
        return [$fecha->year, $fecha->month, 2];
    }

    // ─── Carga de datos ──────────────────────────────────────────────────────

    private function getPermisos(array $empIds, Carbon $desde, Carbon $hasta): \Illuminate\Support\Collection
    {
        return Permiso::with('tipoPermiso')
            ->whereIn('empleado_id', $empIds)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->get();
    }

    private function getIncapacidades(array $empIds, Carbon $desde, Carbon $hasta): \Illuminate\Support\Collection
    {
        return Incapacidad::whereIn('empleado_id', $empIds)
            ->where('fecha_inicio', '<=', $hasta->toDateString())
            ->where('fecha_fin',    '>=', $desde->toDateString())
            ->get();
    }

    private function getVacaciones(array $empIds, Carbon $desde, Carbon $hasta): \Illuminate\Support\Collection
    {
        return Vacacion::whereIn('empleado_id', $empIds)
            ->where('fecha_inicio', '<=', $hasta->toDateString())
            ->where('fecha_fin',    '>=', $desde->toDateString())
            ->get();
    }

    private function getAusencias(array $empIds, Carbon $desde, Carbon $hasta): \Illuminate\Support\Collection
    {
        return AusenciaInjustificada::whereIn('empleado_id', $empIds)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->get();
    }

    private function getEmpleadosFiltrados(array $ids, $sucursalId, $deptoId): array
    {
        if (empty($ids)) return [];

        $query = DB::connection('pgsql')
            ->table('empleados as e')
            ->join('sucursales as s', 's.id', '=', 'e.sucursal_id')
            ->leftJoin('tipos_sucursal as ts', 'ts.id', '=', 's.tipo_sucursal_id')
            ->leftJoin('cargos as c', 'c.id', '=', 'e.cargo_id')
            // Departamento como miembro
            ->leftJoin('departamentos as d', 'd.id', '=', 'e.departamento_id')
            // Departamento como jefe (cuando departamento_id es null)
            ->leftJoin('departamentos as dj', 'dj.jefe_empleado_id', '=', 'e.id')
            ->whereIn('e.id', $ids)
            ->where('e.activo', true);

        if ($sucursalId) {
            $query->where('e.sucursal_id', (int) $sucursalId);
        }

        if ($deptoId) {
            // Incluir miembros y también el jefe del departamento
            $query->where(function ($q) use ($deptoId) {
                $q->where('e.departamento_id', (int) $deptoId)
                  ->orWhere('dj.id', (int) $deptoId);
            });
        }

        return $query
            ->select(
                'e.id',
                'e.codigo',
                DB::raw("CONCAT(e.nombres, ' ', e.apellidos) as nombre"),
                's.nombre as sucursal',
                'ts.codigo as sucursal_tipo',
                'e.sucursal_id',
                'e.departamento_id',
                DB::raw("COALESCE(d.nombre, dj.nombre) as departamento"),
                'c.nombre as cargo'
            )->get()->map(fn($r) => (array) $r)->toArray();
    }

    // ─── Cálculos ─────────────────────────────────────────────────────────────

    /**
     * Días NO trabajados con criterios de descuento (para quincena actual).
     */
    private function calcDiasNoTrabajadosDescuentos(
        int $eid,
        $permisos, $incapacidades, $vacaciones, $ausencias,
        Carbon $desde, Carbon $hasta
    ): array {
        $detalles = [];
        $total    = 0;

        // Permisos sin goce
        foreach ($permisos->where('empleado_id', $eid) as $p) {
            if ($p->tipoPermiso && $p->tipoPermiso->categoria === 'sin_goce') {
                $dias = (float) ($p->dias ?? ($p->horas_solicitadas ? $p->horas_solicitadas / 8 : 0));
                $total += $dias;
                $detalles[] = ['tipo' => 'Permiso sin goce', 'dias' => $dias, 'fecha' => $p->fecha?->toDateString()];
            }
        }

        // Incapacidades privadas (todas) + ISSS solo los días > 3
        foreach ($incapacidades->where('empleado_id', $eid) as $inc) {
            $dias = (int) $inc->dias;
            $diasEnQuincena = $this->diasSolapados($inc->fecha_inicio, $inc->fecha_fin, $desde, $hasta);

            if ($inc->tipo_institucion === 'privada') {
                $total += $diasEnQuincena;
                $detalles[] = ['tipo' => 'Incapacidad privada', 'dias' => $diasEnQuincena, 'fecha' => $inc->fecha_inicio?->toDateString()];
            } elseif ($inc->tipo_institucion === 'isss') {
                // ISSS: primeros 3 días los cubre el empleador, del 4to en adelante descuenta
                $descuento = max(0, $dias - 3);
                $descuentoEnQ = min($descuento, $diasEnQuincena);
                if ($descuentoEnQ > 0) {
                    $total += $descuentoEnQ;
                    $detalles[] = ['tipo' => 'Incapacidad ISSS >3 días', 'dias' => $descuentoEnQ, 'fecha' => $inc->fecha_inicio?->toDateString()];
                }
            }
        }

        // Vacaciones
        foreach ($vacaciones->where('empleado_id', $eid) as $v) {
            $diasEnQ = $this->diasSolapados($v->fecha_inicio, $v->fecha_fin, $desde, $hasta);
            if ($diasEnQ > 0) {
                $total += $diasEnQ;
                $detalles[] = ['tipo' => 'Vacaciones', 'dias' => $diasEnQ, 'fecha' => $v->fecha_inicio?->toDateString()];
            }
        }

        // Ausencias injustificadas
        foreach ($ausencias->where('empleado_id', $eid) as $a) {
            $total += 1;
            $detalles[] = ['tipo' => 'Ausencia injustificada', 'dias' => 1, 'fecha' => $a->fecha?->toDateString()];
        }

        return ['total' => round($total, 2), 'detalles' => $detalles];
    }

    /**
     * TODOS los días sin trabajar (para días propinas de quincena anterior).
     */
    private function calcDiasTodosEventos(
        int $eid,
        $permisos, $incapacidades, $vacaciones, $ausencias,
        Carbon $desde, Carbon $hasta
    ): array {
        $detalles = [];
        $total    = 0;

        foreach ($permisos->where('empleado_id', $eid) as $p) {
            $dias = (float) ($p->dias ?? ($p->horas_solicitadas ? $p->horas_solicitadas / 8 : 0));
            $total += $dias;
            $tipo = $p->tipoPermiso ? $p->tipoPermiso->nombre : 'Permiso';
            $detalles[] = ['tipo' => $tipo, 'dias' => $dias, 'fecha' => $p->fecha?->toDateString()];
        }

        foreach ($incapacidades->where('empleado_id', $eid) as $inc) {
            $diasEnQ = $this->diasSolapados($inc->fecha_inicio, $inc->fecha_fin, $desde, $hasta);
            if ($diasEnQ > 0) {
                $tipo = $inc->tipo_institucion === 'isss' ? 'Incapacidad ISSS' : 'Incapacidad privada';
                $total += $diasEnQ;
                $detalles[] = ['tipo' => $tipo, 'dias' => $diasEnQ, 'fecha' => $inc->fecha_inicio?->toDateString()];
            }
        }

        foreach ($vacaciones->where('empleado_id', $eid) as $v) {
            $diasEnQ = $this->diasSolapados($v->fecha_inicio, $v->fecha_fin, $desde, $hasta);
            if ($diasEnQ > 0) {
                $total += $diasEnQ;
                $detalles[] = ['tipo' => 'Vacaciones', 'dias' => $diasEnQ, 'fecha' => $v->fecha_inicio?->toDateString()];
            }
        }

        foreach ($ausencias->where('empleado_id', $eid) as $a) {
            $total += 1;
            $detalles[] = ['tipo' => 'Ausencia injustificada', 'dias' => 1, 'fecha' => $a->fecha?->toDateString()];
        }

        return ['total' => round($total, 2), 'detalles' => $detalles];
    }

    /**
     * Calcula cuántos días de un rango [inicio, fin] caen dentro de [desde, hasta].
     */
    private function diasSolapados($inicio, $fin, Carbon $desde, Carbon $hasta): int
    {
        $start = max(Carbon::parse($inicio), $desde);
        $end   = min(Carbon::parse($fin), $hasta);
        return max(0, $start->diffInDays($end) + 1);
    }

    /**
     * Calcula horas nocturnas (>= 19:00) por empleado en la quincena.
     * Solo se consideran horarios de tipo 'normal'.
     *
     * @return array<int, float>  empleado_id => horas_nocturnas
     */
    private function getHorasNocturnas(array $empIds, Carbon $desde, Carbon $hasta): array
    {
        if (empty($empIds)) return [];

        $horarios = DB::connection('pgsql')
            ->table('horarios_empleado')
            ->whereIn('empleado_id', $empIds)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->where('tipo', 'normal')
            ->whereNotNull('hora_inicio')
            ->whereNotNull('hora_fin')
            ->get(['empleado_id', 'hora_inicio', 'hora_fin']);

        $result = [];
        $INICIO_NOCTURNO = 19 * 60; // 19:00 = 1140 min

        $timeToMins = function (string $t): int {
            [$hh, $mm] = array_pad(explode(':', $t), 2, '0');
            return (int) $hh * 60 + (int) $mm;
        };

        foreach ($horarios as $h) {
            $eid = (int) $h->empleado_id;
            if (!isset($result[$eid])) $result[$eid] = 0;

            $inicioMins = $timeToMins($h->hora_inicio);
            $finMins    = $timeToMins($h->hora_fin);

            // Turno nocturno cruzando medianoche (ej. 22:00–06:00)
            if ($finMins < $inicioMins) {
                $finMins += 24 * 60;
            }

            if ($finMins > $INICIO_NOCTURNO) {
                $nocturnoDesde = max($inicioMins, $INICIO_NOCTURNO);
                $result[$eid] += ($finMins - $nocturnoDesde) / 60;
            }
        }

        return $result;
    }

    /**
     * Devuelve el tipo de sucursal ('operativa' | 'area_corporativa' | null)
     * para un array de sucursal_id. Usado como fallback cuando el LEFT JOIN
     * en getEmpleadosFiltrados devuelve null (tipo_sucursal_id no mapeado).
     *
     * @param  int[]  $sucursalIds
     * @return array<int, string|null>
     */
    private function getSucursalTiposMap(array $sucursalIds): array
    {
        $ids = array_filter($sucursalIds);
        if (empty($ids)) return [];

        $rows = DB::connection('pgsql')
            ->table('sucursales as s')
            ->leftJoin('tipos_sucursal as ts', 'ts.id', '=', 's.tipo_sucursal_id')
            ->whereIn('s.id', $ids)
            ->select('s.id', 'ts.codigo as sucursal_tipo')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->id] = $row->sucursal_tipo;
        }
        return $map;
    }
}
