<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Http\Controllers\Controller;
use App\Models\RRHH\Plaza;
use Illuminate\Http\Request;

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
