<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Http\Controllers\Controller;
use App\Models\RRHH\Plaza;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlazasController extends Controller
{
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
    }

    public function update(Request $request, int $id)
    {
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
    }

    public function toggleActivo(int $id)
    {
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

        $porDpto = DB::connection('pgsql')->select("
            SELECT
                d.id                                          AS departamento_id,
                COALESCE(d.nombre, 'Sin departamento')        AS departamento,
                COUNT(p.id)::int                              AS total,
                COUNT(e.id)::int                              AS ocupadas,
                (COUNT(p.id) - COUNT(e.id))::int              AS vacantes
            FROM plazas p
            LEFT JOIN departamentos d ON d.id = p.departamento_id
            LEFT JOIN empleados e ON e.plaza_id = p.id AND e.activo = true
            WHERE p.activo = true
            GROUP BY d.id, d.nombre
            HAVING COUNT(p.id) - COUNT(e.id) > 0
            ORDER BY vacantes DESC, d.nombre
        ");

        $detalle = DB::connection('pgsql')->select("
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

        $detallePorDpto = collect($detalle)->groupBy('departamento_id');

        $porDptoConDetalle = collect($porDpto)->map(fn($d) => [
            'departamento_id' => $d->departamento_id,
            'departamento'    => $d->departamento,
            'total'           => $d->total,
            'ocupadas'        => $d->ocupadas,
            'vacantes'        => $d->vacantes,
            'pct'             => $d->total > 0 ? round($d->ocupadas / $d->total * 100, 1) : 0,
            'cargos_vacantes' => $detallePorDpto->get($d->departamento_id, collect())
                ->map(fn($c) => ['cargo' => $c->cargo, 'cantidad' => $c->cantidad])
                ->values(),
        ]);

        return response()->json([
            'resumen'          => [
                'total'         => (int) $global->total,
                'ocupadas'      => (int) $global->ocupadas,
                'vacantes'      => (int) $global->vacantes,
                'pct_cobertura' => $global->total > 0
                    ? round($global->ocupadas / $global->total * 100, 1)
                    : 0,
            ],
            'por_departamento' => $porDptoConDetalle,
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
        $plaza = Plaza::findOrFail($id);

        if ($plaza->empleado()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar: la plaza tiene un empleado asignado.',
            ], 422);
        }

        $plaza->delete();

        return response()->json(null, 204);
    }
}
