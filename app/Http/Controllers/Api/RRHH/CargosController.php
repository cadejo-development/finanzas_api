<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Http\Controllers\Controller;
use App\Models\Cargo;
use Illuminate\Http\Request;

class CargosController extends Controller
{
    public function index(Request $request)
    {
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

        $cargos = $q->withCount(['empleados as total_empleados' => function ($query) {
                        $query->where('activo', true);
                    }])
                    ->withCount('plazas as total_plazas')
                    ->orderBy('nombre')
                    ->get();

        return response()->json($cargos);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'codigo' => 'required|string|max:30|unique:pgsql.cargos,codigo',
            'nombre' => 'required|string|max:120',
            'activo' => 'boolean',
        ]);

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

        $data['aud_usuario'] = auth()->user()?->name ?? 'sistema';
        $cargo->update($data);

        return response()->json($cargo);
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
