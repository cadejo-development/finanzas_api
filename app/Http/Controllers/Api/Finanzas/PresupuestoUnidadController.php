<?php

namespace App\Http\Controllers\Api\Finanzas;

use App\Http\Controllers\Controller;
use App\Models\PresupuestoUnidad;
use App\Models\UserCentroCosto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PresupuestoUnidadController extends Controller
{
    /** GET /api/pagos/presupuestos-unidad — listado general */
    public function index()
    {
        $presupuestos = PresupuestoUnidad::all();
        return response()->json(['data' => $presupuestos]);
    }

    /**
     * GET /api/pagos/mi-presupuesto
     * Devuelve los presupuestos del año actual para los centros de costo
     * asignados al usuario autenticado.
     */
    public function miPresupuesto()
    {
        try {
            $userId = Auth::id();
            $anio   = (int) date('Y');

            // 1) Centros de costo asignados al usuario (pgsql)
            $codigos = UserCentroCosto::where('user_id', $userId)
                ->pluck('centro_costo_codigo');

            if ($codigos->isEmpty()) {
                return response()->json(['success' => true, 'data' => []]);
            }

            // 2) Presupuestos para esos centros (pagos DB)
            $presupuestos = PresupuestoUnidad::whereIn('centro_costo_codigo', $codigos)
                ->where('anio', $anio)
                ->get();

            // 3) Enriquecer con nombre del centro desde pgsql
            $centros = DB::connection('pgsql')
                ->table('centros_costo')
                ->whereIn('codigo', $codigos)
                ->pluck('nombre', 'codigo');

            $data = $presupuestos->map(function ($p) use ($centros) {
                $presupuestado = (float) $p->presupuesto_total;
                $ejecutado     = (float) $p->ejecutado;
                $porcentaje    = $presupuestado > 0 ? round(($ejecutado / $presupuestado) * 100, 1) : 0;
                return [
                    'id'                  => $p->id,
                    'centro_costo_codigo' => $p->centro_costo_codigo,
                    'centro_costo_nombre' => $centros[$p->centro_costo_codigo] ?? $p->centro_costo_codigo,
                    'anio'                => $p->anio,
                    'presupuesto_total'   => $presupuestado,
                    'ejecutado'           => $ejecutado,
                    'disponible'          => max(0, $presupuestado - $ejecutado),
                    'porcentaje'          => $porcentaje,
                ];
            });

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            \Log::error('miPresupuesto error: ' . $e->getMessage());
            return response()->json(['success' => true, 'data' => []]);
        }
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'centro_costo_codigo' => 'required|string|max:20',
            'anio'                => 'required|integer|min:2000|max:2100',
            'presupuesto_total'   => 'required|numeric|min:0',
            'ejecutado'           => 'numeric|min:0',
        ]);
        $presupuesto = PresupuestoUnidad::create(array_merge($data, ['aud_usuario' => Auth::user()?->email ?? 'api']));
        return response()->json(['data' => $presupuesto], 201);
    }

    public function show($id)
    {
        return response()->json(['data' => PresupuestoUnidad::findOrFail($id)]);
    }

    public function update(Request $request, $id)
    {
        $presupuesto = PresupuestoUnidad::findOrFail($id);
        $presupuesto->update($request->only(['centro_costo_codigo', 'anio', 'presupuesto_total', 'ejecutado']));
        return response()->json(['data' => $presupuesto]);
    }

    public function destroy($id)
    {
        PresupuestoUnidad::findOrFail($id)->delete();
        return response()->json(['message' => 'Presupuesto eliminado']);
    }
}

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $presupuestos = PresupuestoUnidad::with('centroCosto')->get();
        return response()->json(['data' => $presupuestos]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'centro_costo_id' => 'required|exists:compras.centros_costo,id',
            'anio' => 'required|integer|min:2000|max:2100',
            'presupuesto_total' => 'required|numeric|min:0',
            'ejecutado' => 'required|numeric|min:0',
            'aud_usuario' => 'required|string|max:50',
        ]);
        $presupuesto = PresupuestoUnidad::create($data);
        return response()->json(['data' => $presupuesto], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $presupuesto = PresupuestoUnidad::with('centroCosto')->findOrFail($id);
        return response()->json(['data' => $presupuesto]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $presupuesto = PresupuestoUnidad::findOrFail($id);
        $data = $request->validate([
            'centro_costo_id' => 'sometimes|required|exists:compras.centros_costo,id',
            'anio' => 'sometimes|required|integer|min:2000|max:2100',
            'presupuesto_total' => 'sometimes|required|numeric|min:0',
            'ejecutado' => 'sometimes|required|numeric|min:0',
            'aud_usuario' => 'sometimes|required|string|max:50',
        ]);
        $presupuesto->update($data);
        return response()->json(['data' => $presupuesto]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $presupuesto = PresupuestoUnidad::findOrFail($id);
        $presupuesto->delete();
        return response()->json(['message' => 'Presupuesto eliminado']);
    }
}
