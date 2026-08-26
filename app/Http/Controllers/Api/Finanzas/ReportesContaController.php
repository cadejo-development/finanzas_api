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
     * fecha_desde, fecha_hasta, buscar, tipo_doc (csv), suc_id, per_page
     */
    public function facturas(Request $request): JsonResponse
    {
        $desde   = $request->input('fecha_desde', '2026-01-01');
        $hasta   = $request->input('fecha_hasta', now()->toDateString());
        $buscar  = $request->input('buscar', '');
        $tipoDocs = array_filter(explode(',', $request->input('tipo_doc', '')));
        $sucId   = $request->input('suc_id');
        $perPage = min((int) $request->input('per_page', 10), 200);

        $base = fn () => BriloFactura::whereBetween('fecha_doc', [$desde, $hasta])
            ->when($buscar, fn ($q) => $q->where(fn ($sub) => $sub
                ->where('prv_nombre',  'ilike', "%{$buscar}%")
                ->orWhere('concepto',   'ilike', "%{$buscar}%")
                ->orWhere('num_doc',    'ilike', "%{$buscar}%")
                ->orWhere('ceco_nombre','ilike', "%{$buscar}%")
                ->orWhere('sucursal_nombre', 'ilike', "%{$buscar}%")))
            ->when($tipoDocs, fn ($q) => $q->whereIn('tipo_doc', $tipoDocs))
            ->when($sucId,    fn ($q) => $q->where('suc_id_brilo', $sucId));

        $query = $base()->orderBy('fecha_doc', 'desc')->orderBy('mco_id', 'desc');

        $paginated = $query->paginate($perPage, [
            'id', 'mco_id', 'fecha_doc', 'fecha_creado',
            'tipo_doc', 'num_doc', 'concepto',
            'suc_id_brilo', 'sucursal_nombre',
            'ceco_id', 'ceco_nombre', 'ceco_sub_id', 'ceco_sub_nombre',
            'prv_id', 'prv_nombre',
            'monto_afecto', 'monto_exento',
            'synced_at',
        ]);

        $montoTotal = $base()->sum(DB::raw('monto_afecto + monto_exento'));

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

    /**
     * GET /api/pagos/reportes/conta/catalogos
     * Valores distintos de sucursal y tipo_doc disponibles en brilo_facturas.
     */
    public function catalogos(): JsonResponse
    {
        $sucursales = BriloFactura::select('suc_id_brilo', 'sucursal_nombre')
            ->whereNotNull('sucursal_nombre')
            ->distinct()
            ->orderBy('sucursal_nombre')
            ->get();

        $tipos = BriloFactura::whereNotNull('tipo_doc')
            ->distinct()
            ->orderBy('tipo_doc')
            ->pluck('tipo_doc');

        return response()->json([
            'sucursales' => $sucursales,
            'tipos_doc'  => $tipos,
        ]);
    }
}
