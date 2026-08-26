<?php

namespace App\Http\Controllers\Api\Finanzas;

use App\Http\Controllers\Controller;
use App\Models\BriloFactura;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportesContaController extends Controller
{
    /**
     * GET /api/pagos/reportes/conta/facturas
     *
     * Parámetros:
     *   fecha_desde  string YYYY-MM-DD  (default: 2026-01-01)
     *   fecha_hasta  string YYYY-MM-DD  (default: hoy)
     *   buscar       string             (proveedor | concepto | num_doc | ceco)
     *   per_page     int    max 200     (default: 50)
     */
    public function facturas(Request $request): JsonResponse
    {
        $desde   = $request->input('fecha_desde', '2026-01-01');
        $hasta   = $request->input('fecha_hasta', now()->toDateString());
        $buscar  = $request->input('buscar', '');
        $perPage = min((int) $request->input('per_page', 50), 200);

        $query = BriloFactura::whereBetween('fecha_doc', [$desde, $hasta])
            ->orderBy('fecha_doc', 'desc')
            ->orderBy('mco_id', 'desc');

        if ($buscar) {
            $query->where(function ($sub) use ($buscar) {
                $sub->where('prv_nombre',  'ilike', "%{$buscar}%")
                    ->orWhere('concepto',   'ilike', "%{$buscar}%")
                    ->orWhere('num_doc',    'ilike', "%{$buscar}%")
                    ->orWhere('ceco_nombre','ilike', "%{$buscar}%");
            });
        }

        $cols = [
            'id', 'mco_id', 'fecha_doc', 'fecha_creado',
            'tipo_doc', 'num_doc', 'concepto',
            'sucursal_nombre', 'ceco_nombre', 'ceco_sub_nombre',
            'prv_nombre', 'monto_afecto', 'monto_exento',
        ];

        $paginated = $query->paginate($perPage, $cols);

        // Monto total del rango/búsqueda (no de la página)
        $totalQuery = BriloFactura::whereBetween('fecha_doc', [$desde, $hasta]);
        if ($buscar) {
            $totalQuery->where(function ($sub) use ($buscar) {
                $sub->where('prv_nombre',  'ilike', "%{$buscar}%")
                    ->orWhere('concepto',   'ilike', "%{$buscar}%")
                    ->orWhere('num_doc',    'ilike', "%{$buscar}%")
                    ->orWhere('ceco_nombre','ilike', "%{$buscar}%");
            });
        }
        $montoTotal = $totalQuery->sum(DB::raw('monto_afecto + monto_exento'));

        return response()->json([
            'data'        => $paginated->items(),
            'meta'        => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
            ],
            'monto_total' => round((float) $montoTotal, 2),
        ]);
    }
}
