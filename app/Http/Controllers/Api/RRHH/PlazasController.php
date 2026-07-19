<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Http\Controllers\Controller;
use App\Models\RRHH\Plaza;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlazasController extends Controller
{
    use \App\Http\Controllers\Api\RRHH\Traits\RRHHCapturesExceptions;

    public function index(Request $request)
    {
        $q = Plaza::with(['cargo:id,nombre,codigo', 'departamento:id,nombre', 'empleado:id,nombres,apellidos,plaza_id'])
                  ->withExists(['empleado as ocupada' => fn($q) => $q->where('activo', true)]);

        if ($request->has('departamento_id') && $request->departamento_id !== '') {
            $val = $request->departamento_id;
            if ($val === 'NULL' || $val === 'null') {
                $q->whereNull('departamento_id');
            } else {
                $q->where('departamento_id', $val);
            }
        }
        if ($request->filled('cargo_id')) {
            $q->where('cargo_id', $request->cargo_id);
        }
        if ($request->has('activo') && $request->activo !== '') {
            $q->where('activo', filter_var($request->activo, FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $q->whereHas('cargo', fn($c) => $c->where('nombre', 'ilike', $search))
              ->orWhere('codigo', 'ilike', $search);
        }

        $plazas = $q->orderBy('departamento_id')->orderBy('id')->get();

        return response()->json($plazas);
    }

    public function store(Request $request)
    {
        return $this->captureAndRespond($request, function () use ($request) {
            $data = $request->validate([
                'cargo_id'        => 'required|exists:pgsql.cargos,id',
                'departamento_id' => 'nullable|exists:pgsql.departamentos,id',
                'codigo'          => 'nullable|string|max:30',
            ]);

            $data['activo']      = true;
            $data['aud_usuario'] = auth()->user()?->name ?? 'sistema';

            $plaza = Plaza::create($data);
            $plaza->load(['cargo:id,nombre,codigo', 'departamento:id,nombre']);

            return response()->json($plaza, 201);
        });
    }

    public function update(Request $request, int $id)
    {
        return $this->captureAndRespond($request, function () use ($request, $id) {
            $plaza = Plaza::findOrFail($id);

            $data = $request->validate([
                'cargo_id'        => 'required|exists:pgsql.cargos,id',
                'departamento_id' => 'nullable|exists:pgsql.departamentos,id',
                'codigo'          => 'nullable|string|max:30',
            ]);

            $data['aud_usuario'] = auth()->user()?->name ?? 'sistema';
            $plaza->update($data);
            $plaza->load(['cargo:id,nombre,codigo', 'departamento:id,nombre']);

            return response()->json($plaza);
        });
    }

    public function toggleActivo(int $id)
    {
        return $this->captureAndRespond(request(), function () use ($id) {
            $plaza = Plaza::findOrFail($id);

            if ($plaza->activo) {
                $ocupada = $plaza->empleado()->where('activo', true)->exists();
                if ($ocupada) {
                    $emp = $plaza->empleado()->where('activo', true)->first();
                    return response()->json([
                        'message' => "No se puede inactivar: la plaza está ocupada por {$emp->nombres} {$emp->apellidos}.",
                    ], 422);
                }
            }

            $plaza->update([
                'activo'      => !$plaza->activo,
                'aud_usuario' => auth()->user()?->name ?? 'sistema',
            ]);

            return response()->json($plaza);
        });
    }

    public function stats()
    {
        $global = DB::connection('pgsql')->selectOne("
            SELECT
                COUNT(p.id)::int                              AS total,
                COUNT(e.id)::int                              AS ocupadas,
                (COUNT(p.id) - COUNT(e.id))::int              AS vacantes
            FROM plazas p
            LEFT JOIN empleados e ON e.plaza_id = p.id AND e.activo = true
            WHERE p.activo = true
        ");

        // Todos los departamentos con plazas activas
        $porDptoVacantes = DB::connection('pgsql')->select("
            SELECT
                d.id                                          AS departamento_id,
                COALESCE(d.nombre, 'Sin departamento')        AS departamento,
                COUNT(p.id)::int                              AS total,
                COUNT(e.id)::int                              AS ocupadas,
                (COUNT(p.id) - COUNT(e.id))::int              AS vacantes,
                CASE WHEN d.nombre ILIKE 'RESTAURANTE%' THEN 'operaciones' ELSE 'administrativo' END AS tipo
            FROM plazas p
            LEFT JOIN departamentos d ON d.id = p.departamento_id
            LEFT JOIN empleados e ON e.plaza_id = p.id AND e.activo = true
            WHERE p.activo = true
            GROUP BY d.id, d.nombre
            ORDER BY d.nombre
        ");

        // Cargos vacantes por departamento (plazas activas sin empleado)
        $detalleVacantes = DB::connection('pgsql')->select("
            SELECT
                p.departamento_id,
                COALESCE(c.nombre, 'Sin puesto')              AS cargo,
                COUNT(*)::int                                  AS cantidad
            FROM plazas p
            LEFT JOIN cargos c ON c.id = p.cargo_id
            LEFT JOIN empleados e ON e.plaza_id = p.id AND e.activo = true
            WHERE p.activo = true AND e.id IS NULL
            GROUP BY p.departamento_id, c.nombre
            ORDER BY cantidad DESC
        ");

        // Departamentos con exceso: más empleados activos que lo autorizado (cargo_plazas_autorizadas)
        $excesoRows = DB::connection('pgsql')->select("
            WITH
            auth AS (
                SELECT departamento_id, cargo_id, cantidad AS n
                FROM cargo_plazas_autorizadas
            ),
            emp AS (
                SELECT departamento_id, cargo_id, COUNT(*)::int AS n
                FROM empleados WHERE activo = true AND departamento_id IS NOT NULL
                GROUP BY departamento_id, cargo_id
            ),
            diff AS (
                SELECT
                    COALESCE(auth.departamento_id, emp.departamento_id) AS dpto_id,
                    COALESCE(auth.cargo_id,        emp.cargo_id)        AS cargo_id,
                    COALESCE(auth.n, 0)                                  AS n_autorizado,
                    COALESCE(emp.n, 0)                                   AS n_emp,
                    COALESCE(emp.n, 0) - COALESCE(auth.n, 0)            AS diferencia
                FROM auth
                FULL OUTER JOIN emp
                  ON emp.departamento_id = auth.departamento_id
                 AND emp.cargo_id        = auth.cargo_id
                WHERE COALESCE(emp.n, 0) > COALESCE(auth.n, 0)
            ),
            plz_totals AS (
                SELECT p2.departamento_id,
                       COUNT(*)::int    AS total_plazas,
                       COUNT(e.id)::int AS total_ocupadas
                FROM plazas p2
                LEFT JOIN empleados e ON e.plaza_id = p2.id AND e.activo = true
                WHERE p2.activo = true
                GROUP BY p2.departamento_id
            )
            SELECT
                diff.dpto_id                                   AS departamento_id,
                COALESCE(d.nombre, 'Sin departamento')         AS departamento,
                SUM(diff.diferencia)::int                      AS exceso,
                COALESCE(pt.total_plazas,   0)                 AS total_plazas,
                COALESCE(pt.total_ocupadas, 0)                 AS total_ocupadas,
                json_agg(
                    json_build_object('cargo', c.nombre, 'cantidad', diff.diferencia)
                    ORDER BY diff.diferencia DESC
                )::text                                        AS cargos_exceso_json
            FROM diff
            JOIN  cargos       c  ON c.id = diff.cargo_id
            LEFT JOIN departamentos d  ON d.id = diff.dpto_id
            LEFT JOIN plz_totals   pt ON pt.departamento_id = diff.dpto_id
            GROUP BY diff.dpto_id, d.nombre, pt.total_plazas, pt.total_ocupadas
            ORDER BY exceso DESC
        ");

        $totalExceso        = (int) collect($excesoRows)->sum('exceso');
        $excesoMap          = collect($excesoRows)->keyBy('departamento_id');
        $detalleVacantesPorDpto = collect($detalleVacantes)->groupBy('departamento_id');

        // Merge: todos los departamentos + exceso
        $porDptoConDetalle = collect($porDptoVacantes)->map(fn($d) => [
            'departamento_id' => $d->departamento_id,
            'departamento'    => $d->departamento,
            'tipo'            => $d->tipo,
            'total'           => $d->total,
            'ocupadas'        => $d->ocupadas,
            'vacantes'        => $d->vacantes,
            'exceso'          => (int) ($excesoMap->get($d->departamento_id)?->exceso ?? 0),
            'pct'             => $d->total > 0 ? round($d->ocupadas / $d->total * 100, 1) : 0,
            'cargos_vacantes' => $detalleVacantesPorDpto->get($d->departamento_id, collect())
                ->map(fn($c) => ['cargo' => $c->cargo, 'cantidad' => $c->cantidad])
                ->values()
                ->toArray(),
            'cargos_exceso'   => json_decode($excesoMap->get($d->departamento_id)?->cargos_exceso_json ?? '[]', true) ?? [],
        ]);

        // Departamentos con SOLO exceso sin plazas registradas
        $dptoIdsYaIncluidos = $porDptoConDetalle->pluck('departamento_id')->filter()->flip();
        $soloExceso = collect($excesoRows)
            ->filter(fn($e) => !$dptoIdsYaIncluidos->has($e->departamento_id))
            ->map(fn($e) => [
                'departamento_id' => $e->departamento_id,
                'departamento'    => $e->departamento,
                'tipo'            => str_starts_with(strtoupper($e->departamento ?? ''), 'RESTAURANTE') ? 'operaciones' : 'administrativo',
                'total'           => (int) $e->total_plazas,
                'ocupadas'        => (int) $e->total_ocupadas,
                'vacantes'        => 0,
                'exceso'          => (int) $e->exceso,
                'pct'             => $e->total_plazas > 0
                    ? round($e->total_ocupadas / $e->total_plazas * 100, 1)
                    : 100,
                'cargos_vacantes' => [],
                'cargos_exceso'   => json_decode($e->cargos_exceso_json, true) ?? [],
            ]);

        $allDptos = $porDptoConDetalle->concat($soloExceso)->values();

        return response()->json([
            'resumen'          => [
                'total'         => (int) $global->total,
                'ocupadas'      => (int) $global->ocupadas,
                'vacantes'      => (int) $global->vacantes,
                'exceso'        => $totalExceso,
                'pct_cobertura' => $global->total > 0
                    ? round($global->ocupadas / $global->total * 100, 1)
                    : 0,
            ],
            'por_departamento' => $allDptos,
        ]);
    }

    public function historial(int $id)
    {
        $plaza = Plaza::findOrFail($id);

        $historial = $plaza->historial()
            ->with('empleado:id,nombres,apellidos,codigo')
            ->get()
            ->map(fn($h) => [
                'id'             => $h->id,
                'empleado_id'    => $h->empleado_id,
                'empleado'       => $h->empleado
                    ? trim("{$h->empleado->nombres} {$h->empleado->apellidos}")
                    : '—',
                'codigo_emp'     => $h->empleado?->codigo,
                'motivo_entrada' => $h->motivo_entrada,
                'fecha_inicio'   => $h->fecha_inicio?->format('Y-m-d'),
                'fecha_fin'      => $h->fecha_fin?->format('Y-m-d'),
                'motivo_salida'  => $h->motivo_salida,
                'notas'          => $h->notas,
                'actual'         => is_null($h->fecha_fin),
            ]);

        return response()->json([
            'plaza'    => [
                'id'     => $plaza->id,
                'codigo' => $plaza->codigo,
                'puesto' => $plaza->puesto,
            ],
            'historial' => $historial,
        ]);
    }

    public function destroy(int $id)
    {
        return $this->captureAndRespond(request(), function () use ($id) {
            $plaza = Plaza::findOrFail($id);

            if ($plaza->empleado()->exists()) {
                return response()->json([
                    'message' => 'No se puede eliminar: la plaza tiene un empleado asignado.',
                ], 422);
            }

            $plaza->delete();

            return response()->json(null, 204);
        });
    }
}
