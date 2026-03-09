<?php

namespace App\Http\Controllers\Api\Finanzas;

use App\Http\Controllers\Controller;
use App\Models\PresupuestoUnidad;
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
            $user = Auth::user();
            $anio = (int) date('Y');

            if (!$user->sucursal_id) {
                return response()->json(['success' => true, 'data' => []]);
            }

            $codigos = DB::connection('pgsql')
                ->table('centros_costo')
                ->where('sucursal_id', $user->sucursal_id)
                ->whereNotNull('padre_id')
                ->pluck('codigo');

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

            // 4) Calcular ejecutado dinámicamente desde solicitudes aprobadas
            $estadoAprobadoId = DB::connection('pagos')
                ->table('estados_solicitud_pago')
                ->where('codigo', 'APROBADO')
                ->value('id');

            $ejecutadoPorCeco = collect();
            if ($estadoAprobadoId) {
                $ejecutadoPorCeco = DB::connection('pagos')
                    ->table('solicitud_pago_detalles as d')
                    ->join('solicitudes_pago as sp', 'sp.id', '=', 'd.solicitud_pago_id')
                    ->whereIn('d.centro_costo_codigo', $codigos)
                    ->where('sp.estado_id', $estadoAprobadoId)
                    ->whereYear('sp.fecha_solicitud', $anio)
                    ->groupBy('d.centro_costo_codigo')
                    ->selectRaw('d.centro_costo_codigo, SUM(d.subtotal_linea) as total_ejecutado')
                    ->pluck('total_ejecutado', 'd.centro_costo_codigo');
            }

            $data = $presupuestos->map(function ($p) use ($centros, $ejecutadoPorCeco) {
                $presupuestado = (float) $p->presupuesto_total;
                $ejecutado     = (float) ($ejecutadoPorCeco[$p->centro_costo_codigo] ?? 0);
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
