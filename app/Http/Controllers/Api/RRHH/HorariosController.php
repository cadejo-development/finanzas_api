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
            ->whereIn('e.id', $empleadoIds)
            ->where('e.activo', true)
            ->orderBy('e.nombres')
            ->select('e.id', 'e.nombres', 'e.apellidos', 'c.nombre as cargo', 'd.nombre as departamento', 'e.sucursal_id')
            ->get();

        // ── Horarios de la semana ─────────────────────────────────────────────
        $horarios = HorarioEmpleado::whereIn('empleado_id', $empleadoIds)
            ->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])
            ->get()
            ->groupBy('empleado_id');

        $resultado = $empleados->map(function ($emp) use ($horarios) {
            $dias = [];
            /** @var \Illuminate\Support\Collection $registros */
            $registros = $horarios->get($emp->id, collect());
            foreach ($registros as $h) {
                $dias[$h->fecha instanceof \Carbon\Carbon ? $h->fecha->toDateString() : substr($h->fecha, 0, 10)] = [
                    'id'          => $h->id,
                    'hora_inicio' => $h->hora_inicio ? substr($h->hora_inicio, 0, 5) : null,
                    'hora_fin'    => $h->hora_fin    ? substr($h->hora_fin,    0, 5) : null,
                    'tipo'        => $h->tipo,
                    'notas'       => $h->notas,
                ];
            }
            return [
                'id'          => $emp->id,
                'nombre'      => trim($emp->nombres . ' ' . $emp->apellidos),
                'cargo'       => $emp->cargo   ?? '',
                'departamento'=> $emp->departamento ?? '',
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
     * POST /rrhh/horarios/bulk
     *
     * Body: { registros: [{empleado_id, fecha, hora_inicio, hora_fin, tipo, notas?}] }
     * Hace upsert (INSERT … ON CONFLICT DO UPDATE).
     */
    public function bulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'registros'                => 'required|array|min:1',
            'registros.*.empleado_id'  => 'required|integer',
            'registros.*.fecha'        => 'required|date_format:Y-m-d',
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
                ['empleado_id' => $row['empleado_id'], 'fecha' => $row['fecha']],
                [
                    'hora_inicio' => $row['hora_inicio'] ?? null,
                    'hora_fin'    => $row['hora_fin']    ?? null,
                    'tipo'        => $row['tipo'],
                    'notas'       => $row['notas'] ?? null,
                    'aud_usuario' => $audUsuario,
                    'updated_at'  => $now,
                ]
            );
            $upserted[] = $horario->id;
        }

        return response()->json(['guardados' => count($upserted)]);
    }

    /**
     * DELETE /rrhh/horarios/{empleadoId}/{fecha}
     * Elimina el registro de ese día (lo convierte en "sin horario").
     */
    public function destroy(int $empleadoId, string $fecha): JsonResponse
    {
        $request = request();
        $allowedIds = $this->resolverEmpleadosIds($request);

        if (!in_array($empleadoId, $allowedIds)) {
            abort(403, 'No tiene permisos sobre este empleado.');
        }

        HorarioEmpleado::where('empleado_id', $empleadoId)
            ->where('fecha', $fecha)
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
        if ($this->esAdminRrhh()) {
            $sucursalId = $request->query('sucursal_id');
            if ($sucursalId) {
                return DB::connection('pgsql')
                    ->table('empleados')
                    ->where('sucursal_id', $sucursalId)
                    ->where('activo', true)
                    ->pluck('id')
                    ->map(fn($id) => (int)$id)
                    ->all();
            }
            return $this->todosEmpleadosActivos();
        }

        return array_map('intval', $this->getSubordinadosIds());
    }

    /**
     * Lista de sucursales: solo para rrhh_admin (para el selector del frontend).
     * Jefatura recibe null.
     */
    private function getSucursalesParaSelector(): ?array
    {
        if (!$this->esAdminRrhh()) return null;

        return DB::connection('pgsql')
            ->table('sucursales')
            ->where('activa', true)
            ->orderBy('nombre')
            ->select('id', 'nombre')
            ->get()
            ->toArray();
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
}
