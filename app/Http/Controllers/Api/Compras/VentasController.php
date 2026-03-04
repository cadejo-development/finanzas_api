<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\VentaSemanal;
use App\Models\VentaSemanalDetalle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
// use PhpOffice\PhpSpreadsheet\IOFactory; // TODO: habilitar cuando se necesite importar xlsx

class VentasController extends Controller
{
    /**
     * GET /api/compras/ventas
     * Lista de cabeceras de ventas semanales (paginado).
     */
    public function index(Request $request): JsonResponse
    {
        $query = VentaSemanal::with('detalles')
            ->orderByDesc('semana_inicio');

        if ($request->filled('sucursal_id')) {
            $query->where('sucursal_id', $request->sucursal_id);
        }

        $ventas = $query->get()->map(fn($v) => [
            'id'             => $v->id,
            'sucursal_id'    => $v->sucursal_id,
            'semana_inicio'  => $v->semana_inicio?->format('Y-m-d'),
            'archivo_nombre' => $v->archivo_nombre,
            'importado_por'  => $v->importado_por,
            'total_items'    => $v->detalles->count(),
            'total_vendido'  => round($v->detalles->sum('total'), 2),
            'created_at'     => $v->created_at?->toISOString(),
        ]);

        return response()->json(['success' => true, 'data' => $ventas]);
    }

    /**
     * GET /api/compras/ventas/{id}
     * Detalle de una venta semanal con todos sus líneas.
     */
    public function show(int $id): JsonResponse
    {
        $venta = VentaSemanal::with('detalles')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => [
                'id'            => $venta->id,
                'sucursal_id'   => $venta->sucursal_id,
                'semana_inicio' => $venta->semana_inicio?->format('Y-m-d'),
                'total_vendido' => round($venta->detalles->sum('total'), 2),
                'detalles'      => $venta->detalles->map(fn($d) => [
                    'producto_codigo'  => $d->producto_codigo,
                    'producto_nombre'  => $d->producto_nombre,
                    'categoria_key'    => $d->categoria_key,
                    'cantidad_vendida' => $d->cantidad_vendida,
                    'precio_unitario'  => $d->precio_unitario,
                    'total'            => $d->total,
                ]),
            ],
        ]);
    }

    /**
     * POST /api/compras/ventas/import
     * Importa ventas desde un archivo xlsx/xls/csv.
     *
     * Formato esperado del archivo:
     * Columna A: Código    B: Producto   C: Categoría
     * Columna D: Cantidad  E: Precio Unitario
     * (La primera fila es cabecera, desde la fila 2 vienen datos)
     */
    public function import(Request $request): JsonResponse
    {
        // TODO: habilitar cuando se instale phpoffice/phpspreadsheet
        return response()->json([
            'success' => false,
            'message' => 'Funcionalidad de importación no disponible aún.',
        ], 501);
    }

    /**
     * GET /api/compras/ventas/sugerencia
     * Calcula el promedio de ventas históricas para sugerir cantidades de pedido.
     *
     * Params: sucursal_id, semanas (int, default 4), factor (float, default 1.0)
     */
    public function sugerencia(Request $request): JsonResponse
    {
        $request->validate([
            'sucursal_id' => 'required|integer|min:1',
            'semanas'     => 'integer|min:1|max:52',
            'factor'      => 'numeric|min:0.1|max:10',
        ]);

        $sucursalId = (int) $request->sucursal_id;
        $semanas    = (int) ($request->semanas ?? 4);
        $factor     = (float) ($request->factor ?? 1.0);

        // Obtener las últimas N semanas de ventas para esta sucursal
        $ventasIds = VentaSemanal::where('sucursal_id', $sucursalId)
            ->orderByDesc('semana_inicio')
            ->limit($semanas)
            ->pluck('id');

        if ($ventasIds->isEmpty()) {
            return response()->json([
                'success'     => true,
                'data'        => [],
                'semanas_base' => 0,
                'factor'      => $factor,
                'message'     => 'No hay historial de ventas para esta sucursal.',
            ]);
        }

        // Calcular promedio por producto
        $promedios = VentaSemanalDetalle::whereIn('venta_semanal_id', $ventasIds)
            ->select(
                'producto_codigo',
                'producto_nombre',
                'categoria_key',
                DB::raw('AVG(cantidad_vendida) as promedio_cantidad'),
                DB::raw('AVG(precio_unitario) as promedio_precio'),
                DB::raw('COUNT(*) as semanas_con_datos')
            )
            ->groupBy('producto_codigo', 'producto_nombre', 'categoria_key')
            ->orderBy('producto_codigo')
            ->get()
            ->map(fn($p) => [
                'producto_codigo'     => $p->producto_codigo,
                'producto_nombre'     => $p->producto_nombre,
                'categoria_key'       => $p->categoria_key,
                'promedio_cantidad'   => round((float)$p->promedio_cantidad, 2),
                'cantidad_sugerida'   => round((float)$p->promedio_cantidad * $factor, 2),
                'promedio_precio'     => round((float)$p->promedio_precio, 2),
                'semanas_con_datos'   => (int)$p->semanas_con_datos,
            ]);

        return response()->json([
            'success'      => true,
            'data'         => $promedios,
            'semanas_base' => $ventasIds->count(),
            'factor'       => $factor,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Busca la clave de columna (A, B, C...) en la fila de cabecera
     * que coincida con alguno de los nombres posibles.
     */
    private function findCol(array $header, array $posibleNombres): ?string
    {
        foreach ($header as $key => $value) {
            foreach ($posibleNombres as $nombre) {
                if (str_contains(strtolower($value), $nombre)) {
                    return $key;
                }
            }
        }
        return null;
    }
}
