<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Http\Controllers\Controller;
use App\Models\Cargo;
use App\Models\CargoPlazaAutorizada;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CargosController extends Controller
{
    public function index(Request $request)
    {
        $dptoId = $request->filled('departamento_id') ? (int) $request->departamento_id : null;

        $q = Cargo::query();

        if ($request->filled('search')) {
            $q->where(function ($query) use ($request) {
                $search = '%' . $request->search . '%';
                $query->where('nombre', 'ilike', $search)
                      ->orWhere('codigo', 'ilike', $search);
            });
        }

        if ($request->has('activo') && $request->activo !== '') {
            $q->where('activo', filter_var($request->activo, FILTER_VALIDATE_BOOLEAN));
        }

        // Filtrar sólo cargos con plazas en el departamento dado
        if ($dptoId) {
            $q->whereHas('plazas', fn($p) => $p->where('departamento_id', $dptoId));
        }

        $cargos = $q->with(['plazas' => fn($q) =>
                        $q->select('id', 'cargo_id', 'departamento_id', 'activo')
                          ->with('departamento:id,nombre')
                    ])
                    ->withCount(['empleados as total_empleados' => fn($q) =>
                        $q->where('activo', true)
                          ->when($dptoId, fn($q) => $q->where('departamento_id', $dptoId))
                    ])
                    ->withCount(['plazas as total_plazas' => fn($q) =>
                        $q->where('activo', true)
                          ->when($dptoId, fn($q) => $q->where('departamento_id', $dptoId))
                    ])
                    ->orderBy('nombre')
                    ->get()
                    ->map(function ($c) use ($dptoId) {
                        $plazas = $dptoId
                            ? $c->plazas->where('departamento_id', $dptoId)
                            : $c->plazas;

                        $c->departamentos = $plazas
                            ->whereNotNull('departamento_id')
                            ->map(fn($p) => ['id' => $p->departamento_id, 'nombre' => $p->departamento?->nombre])
                            ->filter(fn($d) => $d['nombre'])
                            ->unique('id')
                            ->values();
                        unset($c->plazas);
                        return $c;
                    });

        return response()->json($cargos);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'codigo' => 'required|string|max:30|unique:pgsql.cargos,codigo',
            'nombre' => 'required|string|max:120',
            'activo' => 'boolean',
        ]);

        $data['nombre']      = $this->normalizarNombre($data['nombre']);
        $data['aud_usuario'] = auth()->user()?->name ?? 'sistema';
        $cargo = Cargo::create($data);

        return response()->json($cargo, 201);
    }

    public function update(Request $request, int $id)
    {
        $cargo = Cargo::findOrFail($id);

        $data = $request->validate([
            'codigo' => 'required|string|max:30|unique:pgsql.cargos,codigo,' . $id,
            'nombre' => 'required|string|max:120',
        ]);

        $data['nombre']      = $this->normalizarNombre($data['nombre']);
        $data['aud_usuario'] = auth()->user()?->name ?? 'sistema';
        $cargo->update($data);

        return response()->json($cargo);
    }

    public function headcount(int $id)
    {
        Cargo::findOrFail($id);

        // Departamentos donde el cargo tiene empleados activos O plazas activas
        $rows = DB::connection('pgsql')->select("
            SELECT
                d.id                            AS departamento_id,
                d.nombre                        AS departamento,
                COALESCE(emp.n, 0)::int         AS empleados,
                COALESCE(auth.cantidad, 0)::int AS autorizado
            FROM (
                SELECT departamento_id FROM empleados
                WHERE cargo_id = :cid1 AND activo = true AND departamento_id IS NOT NULL
                GROUP BY departamento_id
                UNION
                SELECT departamento_id FROM plazas
                WHERE cargo_id = :cid2 AND activo = true AND departamento_id IS NOT NULL
                GROUP BY departamento_id
            ) rel
            JOIN departamentos d ON d.id = rel.departamento_id
            LEFT JOIN (
                SELECT departamento_id, COUNT(*)::int AS n
                FROM empleados
                WHERE cargo_id = :cid3 AND activo = true AND departamento_id IS NOT NULL
                GROUP BY departamento_id
            ) emp ON emp.departamento_id = rel.departamento_id
            LEFT JOIN cargo_plazas_autorizadas auth
                ON auth.departamento_id = rel.departamento_id AND auth.cargo_id = :cid4
            ORDER BY d.nombre
        ", ['cid1' => $id, 'cid2' => $id, 'cid3' => $id, 'cid4' => $id]);

        return response()->json($rows);
    }

    public function updateHeadcount(Request $request, int $id)
    {
        Cargo::findOrFail($id);

        $data = $request->validate([
            'items'                   => 'required|array',
            'items.*.departamento_id' => 'required|integer|exists:pgsql.departamentos,id',
            'items.*.cantidad'        => 'required|integer|min:0',
        ]);

        foreach ($data['items'] as $item) {
            CargoPlazaAutorizada::updateOrCreate(
                ['cargo_id' => $id, 'departamento_id' => $item['departamento_id']],
                ['cantidad' => $item['cantidad']]
            );
        }

        return response()->json(['ok' => true]);
    }

    private function normalizarNombre(string $nombre): string
    {
        $preps  = ['de', 'del', 'la', 'el', 'los', 'las', 'en', 'y', 'a', 'por', 'para', 'con', 'al'];
        $words  = preg_split('/\s+/', mb_strtolower(trim($nombre), 'UTF-8'));
        $result = [];
        foreach ($words as $i => $word) {
            if ($i > 0 && in_array($word, $preps)) {
                $result[] = $word;
            } else {
                $result[] = mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($word, 1, null, 'UTF-8');
            }
        }
        return implode(' ', $result);
    }

    public function toggleActivo(int $id)
    {
        $cargo = Cargo::findOrFail($id);

        // No se puede inactivar si tiene plazas activas
        if ($cargo->activo) {
            $plazasActivas = $cargo->plazas()->where('activo', true)->count();
            if ($plazasActivas > 0) {
                return response()->json([
                    'message' => "No se puede inactivar: el puesto tiene {$plazasActivas} plaza(s) activa(s).",
                ], 422);
            }
        }

        $cargo->update([
            'activo'       => !$cargo->activo,
            'aud_usuario'  => auth()->user()?->name ?? 'sistema',
        ]);

        return response()->json($cargo);
    }
}
