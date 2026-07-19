<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\HorarioEmpleado;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Gestión de horarios semanales por empleado.
 *
 * Roles:
 *  - jefatura   → solo el equipo asignado a su departamento/sucursal
 *  - rrhh_admin → todos, con ?sucursal_id=N opcional para filtrar
 */
class HorariosController extends RRHHBaseController
{
    use \App\Http\Controllers\Api\RRHH\Traits\RRHHCapturesExceptions;
    /**
     * GET /rrhh/horarios
     *
     * Params:
     *  - semana_inicio  (date Y-m-d, lunes de la semana)  requerido
     *  - sucursal_id    (int) solo para rrhh_admin
     *
     * Retorna:
     * {
     *   semana_inicio, semana_fin,
     *   sucursales: [{id, nombre}]  (solo rrhh_admin, para el selector)
     *   empleados: [
     *     { id, nombre, cargo, departamento, dias: { 'YYYY-MM-DD': {id?, hora_inicio, hora_fin, tipo} } }
     *   ]
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $semanaInicio = $request->query('semana_inicio');
        if (!$semanaInicio) {
            // Por defecto: lunes de la semana actual
            $semanaInicio = Carbon::now()->startOfWeek(Carbon::MONDAY)->toDateString();
        }

        $inicio = Carbon::parse($semanaInicio)->startOfWeek(Carbon::MONDAY);
        $fin    = $inicio->copy()->endOfWeek(Carbon::SUNDAY);

        // ── Universo de empleados según rol ──────────────────────────────────
        $empleadoIds = $this->resolverEmpleadosIds($request);

        if (empty($empleadoIds)) {
            return response()->json([
                'semana_inicio' => $inicio->toDateString(),
                'semana_fin'    => $fin->toDateString(),
                'empleados'     => [],
                'sucursales'    => $this->getSucursalesParaSelector(),
            ]);
        }

        // ── Datos de empleados ────────────────────────────────────────────────
        $empleados = DB::connection('pgsql')
            ->table('empleados as e')
            ->leftJoin('cargos as c', 'e.cargo_id', '=', 'c.id')
            ->leftJoin('departamentos as d', 'e.departamento_id', '=', 'd.id')
            ->leftJoin('sucursales as s', 's.id', '=', 'e.sucursal_id')
            ->whereIn('e.id', $empleadoIds)
            ->where('e.activo', true)
            ->orderBy('e.nombres')
            ->select('e.id', 'e.nombres', 'e.apellidos', 'c.nombre as cargo', 'd.nombre as departamento', 'e.sucursal_id', 's.nombre as sucursal')
            ->get();

        // ── Horarios de la semana ─────────────────────────────────────────────
        $horarios = HorarioEmpleado::whereIn('empleado_id', $empleadoIds)
            ->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])
            ->get()
            ->groupBy('empleado_id');

        // ── Eventos automáticos aprobados (vacaciones, permisos, incapacidades) ──
        $eventosAuto = $this->getEventosAuto($empleadoIds, $inicio, $fin);

        $resultado = $empleados->map(function ($emp) use ($horarios, $eventosAuto) {
            $dias = [];
            /** @var \Illuminate\Support\Collection $registros */
            $registros = $horarios->get($emp->id, collect());
            foreach ($registros as $h) {
                $fecha = $h->fecha instanceof \Carbon\Carbon ? $h->fecha->toDateString() : substr($h->fecha, 0, 10);
                if (!isset($dias[$fecha])) $dias[$fecha] = [];
                $dias[$fecha][] = [
                    'id'          => $h->id,
                    'hora_inicio' => $h->hora_inicio ? substr($h->hora_inicio, 0, 5) : null,
                    'hora_fin'    => $h->hora_fin    ? substr($h->hora_fin,    0, 5) : null,
                    'tipo'        => $h->tipo,
                    'parte'       => $h->parte ?? 1,
                    'notas'       => $h->notas,
                ];
            }
            // Overlay eventos automáticos (no reemplazan turnos de trabajo, suman info)
            foreach ($eventosAuto[$emp->id] ?? [] as $fecha => $evento) {
                if (!isset($dias[$fecha])) $dias[$fecha] = [];
                // Solo agregar si no existe ya un evento auto en esa fecha
                $yaTieneAuto = collect($dias[$fecha])->contains('auto', true);
                if (!$yaTieneAuto) {
                    $dias[$fecha][] = $evento;
                }
            }
            return [
                'id'          => $emp->id,
                'nombre'      => trim($emp->nombres . ' ' . $emp->apellidos),
                'cargo'       => $emp->cargo        ?? '',
                'departamento'=> $emp->departamento ?? '',
                'sucursal'    => $emp->sucursal     ?? '',
                'dias'        => $dias,
            ];
        });

        return response()->json([
            'semana_inicio' => $inicio->toDateString(),
            'semana_fin'    => $fin->toDateString(),
            'empleados'     => $resultado->values(),
            'sucursales'    => $this->getSucursalesParaSelector(),
        ]);
    }

    /**
     * GET /rrhh/horarios/mi-horario
     * Devuelve el horario semanal del empleado autenticado (solo lectura).
     */
    public function miHorario(Request $request): JsonResponse
    {
        $semanaInicio = $request->query('semana_inicio');
        $inicio = $semanaInicio
            ? Carbon::parse($semanaInicio)->startOfWeek(Carbon::MONDAY)
            : Carbon::now()->startOfWeek(Carbon::MONDAY);
        $fin = $inicio->copy()->endOfWeek(Carbon::SUNDAY);

        // Buscar el empleado_id del usuario autenticado
        $user = Auth::user();
        $empleado = DB::connection('pgsql')
            ->table('empleados as e')
            ->leftJoin('cargos as c', 'e.cargo_id', '=', 'c.id')
            ->where('e.user_id', $user->id)
            ->where('e.activo', true)
            ->select('e.id', 'e.nombres', 'e.apellidos', 'c.nombre as cargo')
            ->first();

        if (!$empleado) {
            return response()->json(['dias' => [], 'semana_inicio' => $inicio->toDateString(), 'semana_fin' => $fin->toDateString()]);
        }

        $horarios = HorarioEmpleado::where('empleado_id', $empleado->id)
            ->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])
            ->get();

        $dias = [];
        foreach ($horarios as $h) {
            $fecha = $h->fecha instanceof \Carbon\Carbon ? $h->fecha->toDateString() : substr($h->fecha, 0, 10);
            if (!isset($dias[$fecha])) $dias[$fecha] = [];
            $dias[$fecha][] = [
                'hora_inicio' => $h->hora_inicio ? substr($h->hora_inicio, 0, 5) : null,
                'hora_fin'    => $h->hora_fin    ? substr($h->hora_fin,    0, 5) : null,
                'tipo'        => $h->tipo,
                'parte'       => $h->parte ?? 1,
                'notas'       => $h->notas,
            ];
        }

        return response()->json([
            'semana_inicio' => $inicio->toDateString(),
            'semana_fin'    => $fin->toDateString(),
            'empleado'      => [
                'nombre' => trim($empleado->nombres . ' ' . $empleado->apellidos),
                'cargo'  => $empleado->cargo ?? '',
            ],
            'dias' => $dias,
        ]);
    }

    /**
     * POST /rrhh/horarios/bulk
     *
     * Body: { registros: [{empleado_id, fecha, parte, hora_inicio, hora_fin, tipo, notas?}] }
     * Hace upsert (INSERT … ON CONFLICT DO UPDATE).
     */
    public function bulk(Request $request): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request) {
            $validated = $request->validate([
                'registros'                => 'required|array|min:1',
                'registros.*.empleado_id'  => 'required|integer',
                'registros.*.fecha'        => 'required|date_format:Y-m-d',
                'registros.*.parte'        => 'required|integer|min:1|max:3',
                'registros.*.hora_inicio'  => 'nullable|date_format:H:i',
                'registros.*.hora_fin'     => 'nullable|date_format:H:i',
                'registros.*.tipo'         => 'required|in:normal,libre,vacacion,dia_cadejo,incapacidad',
                'registros.*.notas'        => 'nullable|string|max:200',
            ]);

            $allowedIds = $this->resolverEmpleadosIds($request);
            $now        = now();
            $audUsuario = Auth::user()?->name ?? 'sistema';

            $upserted = [];
            foreach ($validated['registros'] as $row) {
                if (!in_array($row['empleado_id'], $allowedIds)) {
                    continue; // no tiene permisos sobre este empleado
                }

                $horario = HorarioEmpleado::updateOrCreate(
                    ['empleado_id' => $row['empleado_id'], 'fecha' => $row['fecha'], 'parte' => $row['parte']],
                    [
                        'hora_inicio' => $row['hora_inicio'] ?? null,
                        'hora_fin'    => $row['hora_fin']    ?? null,
                        'tipo'        => $row['tipo'],
                        'parte'       => $row['parte'],
                        'notas'       => $row['notas'] ?? null,
                        'aud_usuario' => $audUsuario,
                        'updated_at'  => $now,
                    ]
                );
                $upserted[] = $horario->id;
            }

            return response()->json(['guardados' => count($upserted)]);
        });
    }

    /**
     * DELETE /rrhh/horarios/{empleadoId}/{fecha}/{parte}
     * Elimina la parte específica del turno de ese día (1, 2 ó 3).
     */
    public function destroy(int $empleadoId, string $fecha, int $parte): JsonResponse
    {
        $request = request();
        $allowedIds = $this->resolverEmpleadosIds($request);

        if (!in_array($empleadoId, $allowedIds)) {
            abort(403, 'No tiene permisos sobre este empleado.');
        }

        HorarioEmpleado::where('empleado_id', $empleadoId)
            ->where('fecha', $fecha)
            ->where('parte', $parte)
            ->delete();

        return response()->json(['ok' => true]);
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    /**
     * Resuelve el listado de IDs de empleados que el usuario puede gestionar,
     * aplicando filtro de sucursal para rrhh_admin.
     */
    private function resolverEmpleadosIds(Request $request): array
    {
        $esAdminOGerencia = $this->esAdminRrhh() || $this->esGerenciaOps();
        $sucursalId = $request->query('sucursal_id');

        if ($esAdminOGerencia) {
            if ($sucursalId) {
                return DB::connection('pgsql')
                    ->table('empleados')
                    ->where('sucursal_id', $sucursalId)
                    ->where('activo', true)
                    ->pluck('id')
                    ->map(fn($id) => (int)$id)
                    ->all();
            }
            if ($this->esAdminRrhh()) return $this->todosEmpleadosActivos();
            return array_map('intval', $this->getSubordinadosIds());
        }

        $todos = array_map('intval', $this->getSubordinadosIds());

        // Incluir el propio empleado del jefe/gerente (getSubordinadosIds lo excluye)
        $propioId = DB::connection('pgsql')
            ->table('empleados')
            ->where('user_id', $this->getEffectiveUser()->id)
            ->where('activo', true)
            ->value('id');
        if ($propioId && !in_array((int) $propioId, $todos)) {
            $todos[] = (int) $propioId;
        }

        if ($sucursalId && !empty($todos)) {
            return DB::connection('pgsql')
                ->table('empleados')
                ->whereIn('id', $todos)
                ->where('sucursal_id', (int)$sucursalId)
                ->where('activo', true)
                ->pluck('id')
                ->map(fn($id) => (int)$id)
                ->all();
        }
        return $todos;
    }

    /**
     * Lista de sucursales para el selector del frontend.
     * Admin/gerencia_ops: todas las sucursales operativas.
     * Jefatura: sucursales distintas de su equipo (null si solo tiene una).
     */
    private function getSucursalesParaSelector(): ?array
    {
        if ($this->esAdminRrhh() || $this->esGerenciaOps()) {
            return DB::connection('pgsql')
                ->table('sucursales as s')
                ->leftJoin('tipos_sucursal as t', 't.id', 's.tipo_sucursal_id')
                ->where('s.activa', true)
                ->where('t.codigo', 'operativa')
                ->orderBy('s.nombre')
                ->select('s.id', 's.nombre')
                ->get()
                ->toArray();
        }

        $ids = $this->getSubordinadosIds();
        if (empty($ids)) return null;

        $sucursales = DB::connection('pgsql')
            ->table('empleados as e')
            ->join('sucursales as s', 's.id', '=', 'e.sucursal_id')
            ->whereIn('e.id', $ids)
            ->where('e.activo', true)
            ->whereNotNull('e.sucursal_id')
            ->orderBy('s.nombre')
            ->select('s.id', 's.nombre')
            ->distinct()
            ->get()
            ->toArray();

        return count($sucursales) > 1 ? $sucursales : null;
    }

    /**
     * Todos los IDs de empleados activos para rrhh_admin sin filtro extra.
     */
    private function todosEmpleadosActivos(): array
    {
        return DB::connection('pgsql')
            ->table('empleados')
            ->where('activo', true)
            ->pluck('id')
            ->map(fn($id) => (int)$id)
            ->all();
    }

    /**
     * Devuelve eventos automáticos (vacaciones, permisos, incapacidades aprobadas)
     * agrupados por empleado_id y fecha.
     * Formato: [ empleado_id => [ 'YYYY-MM-DD' => evento, ... ], ... ]
     */
    private function getEventosAuto(array $empleadoIds, Carbon $inicio, Carbon $fin): array
    {
        if (empty($empleadoIds)) return [];

        $eventos = [];
        $inicioStr = $inicio->toDateString();
        $finStr    = $fin->toDateString();

        // ── Vacaciones aprobadas ─────────────────────────────────────────────
        $vacaciones = DB::connection('rrhh')
            ->table('vacaciones')
            ->whereIn('empleado_id', $empleadoIds)
            ->where('estado', 'aprobado')
            ->where('fecha_inicio', '<=', $finStr)
            ->where('fecha_fin',    '>=', $inicioStr)
            ->select('empleado_id', 'fecha_inicio', 'fecha_fin')
            ->get();

        foreach ($vacaciones as $v) {
            $cur = Carbon::parse($v->fecha_inicio)->max($inicio)->copy();
            $end = Carbon::parse($v->fecha_fin)->min($fin);
            while ($cur->lte($end)) {
                $f = $cur->toDateString();
                $eventos[$v->empleado_id][$f] = ['tipo' => 'vacacion', 'auto' => true, 'parte' => 99];
                $cur->addDay();
            }
        }

        // ── Permisos aprobados ───────────────────────────────────────────────
        $permisos = DB::connection('rrhh')
            ->table('permisos as p')
            ->join('tipos_permiso as tp', 'tp.id', '=', 'p.tipo_permiso_id')
            ->whereIn('p.empleado_id', $empleadoIds)
            ->where('p.estado', 'aprobado')
            ->whereBetween('p.fecha', [$inicioStr, $finStr])
            ->select('p.empleado_id', 'p.fecha', 'tp.categoria')
            ->get();

        foreach ($permisos as $p) {
            // Si ya tiene vacación ese día, no sobreescribir
            if (isset($eventos[$p->empleado_id][$p->fecha])) continue;
            $tipo = ($p->categoria === 'cadejo') ? 'dia_cadejo' : 'permiso';
            $eventos[$p->empleado_id][$p->fecha] = ['tipo' => $tipo, 'auto' => true, 'parte' => 99];
        }

        // ── Incapacidades registradas ────────────────────────────────────────
        $incapacidades = DB::connection('rrhh')
            ->table('incapacidades')
            ->whereIn('empleado_id', $empleadoIds)
            ->where('fecha_inicio', '<=', $finStr)
            ->where('fecha_fin',    '>=', $inicioStr)
            ->select('empleado_id', 'fecha_inicio', 'fecha_fin')
            ->get();

        foreach ($incapacidades as $inc) {
            $cur = Carbon::parse($inc->fecha_inicio)->max($inicio)->copy();
            $end = Carbon::parse($inc->fecha_fin)->min($fin);
            while ($cur->lte($end)) {
                $f = $cur->toDateString();
                if (!isset($eventos[$inc->empleado_id][$f])) {
                    $eventos[$inc->empleado_id][$f] = ['tipo' => 'incapacidad', 'auto' => true, 'parte' => 99];
                }
                $cur->addDay();
            }
        }

        return $eventos;
    }
}
