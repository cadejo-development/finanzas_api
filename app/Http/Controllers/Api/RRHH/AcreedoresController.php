<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Http\Controllers\Controller;
use App\Models\RRHH\Acreedor;
use App\Models\RRHH\TipoAcreedor;
use Illuminate\Http\Request;

class AcreedoresController extends Controller
{
    // Catálogo de tipos (lectura + toggle simple)
    public function tiposIndex()
    {
        return response()->json(TipoAcreedor::orderBy('nombre')->get());
    }

    public function tiposStore(Request $request)
    {
        $data = $request->validate(['nombre' => 'required|string|max:100|unique:pgsql.tipos_acreedor,nombre']);
        $data['aud_usuario'] = auth()->user()?->name ?? 'sistema';
        return response()->json(TipoAcreedor::create($data), 201);
    }

    public function tiposUpdate(Request $request, int $id)
    {
        $tipo = TipoAcreedor::findOrFail($id);
        $data = $request->validate(['nombre' => 'required|string|max:100|unique:pgsql.tipos_acreedor,nombre,' . $id]);
        $data['aud_usuario'] = auth()->user()?->name ?? 'sistema';
        $tipo->update($data);
        return response()->json($tipo);
    }

    public function tiposToggle(int $id)
    {
        $tipo = TipoAcreedor::findOrFail($id);
        $tipo->update(['activo' => !$tipo->activo, 'aud_usuario' => auth()->user()?->name ?? 'sistema']);
        return response()->json($tipo);
    }

    // Catálogo de acreedores
    public function index(Request $request)
    {
        $q = Acreedor::with('tipo:id,nombre');

        if ($request->filled('search'))
            $q->where('nombre', 'ilike', '%' . $request->search . '%');
        if ($request->has('activo') && $request->activo !== '')
            $q->where('activo', filter_var($request->activo, FILTER_VALIDATE_BOOLEAN));
        if ($request->filled('tipo_acreedor_id'))
            $q->where('tipo_acreedor_id', $request->tipo_acreedor_id);

        return response()->json($q->orderBy('nombre')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'tipo_acreedor_id' => 'required|exists:pgsql.tipos_acreedor,id',
            'nombre'           => 'required|string|max:150',
        ]);
        $data['aud_usuario'] = auth()->user()?->name ?? 'sistema';
        $acreedor = Acreedor::create($data);
        return response()->json($acreedor->load('tipo:id,nombre'), 201);
    }

    public function update(Request $request, int $id)
    {
        $acreedor = Acreedor::findOrFail($id);
        $data = $request->validate([
            'tipo_acreedor_id' => 'required|exists:pgsql.tipos_acreedor,id',
            'nombre'           => 'required|string|max:150',
        ]);
        $data['aud_usuario'] = auth()->user()?->name ?? 'sistema';
        $acreedor->update($data);
        return response()->json($acreedor->load('tipo:id,nombre'));
    }

    public function toggleActivo(int $id)
    {
        $acreedor = Acreedor::findOrFail($id);
        $acreedor->update(['activo' => !$acreedor->activo, 'aud_usuario' => auth()->user()?->name ?? 'sistema']);
        return response()->json($acreedor->load('tipo:id,nombre'));
    }
}
