<?php

namespace App\Http\Controllers\Api\Ventas;

use App\Http\Controllers\Controller;
use App\Models\Ventas\VentaOrden;
use App\Models\Ventas\VentaAprobacion;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $inicioMes = now()->startOfMonth();
        $finMes    = now()->endOfMonth();

        // Ventas del mes (órdenes aprobadas/completadas)
        $ventasMes = VentaOrden::whereBetween('created_at', [$inicioMes, $finMes])
            ->whereIn('estado', ['aprobada', 'completada'])
            ->selectRaw('SUM(total) as total_ventas, COUNT(*) as num_ordenes')
            ->first();

        // Ventas contado vs crédito
        $ventasTipo = VentaOrden::whereBetween('created_at', [$inicioMes, $finMes])
            ->whereIn('estado', ['aprobada', 'completada'])
            ->selectRaw('tipo_venta, SUM(total) as total, COUNT(*) as cantidad')
            ->groupBy('tipo_venta')
            ->get()
            ->keyBy('tipo_venta');

        // Créditos pendientes de cobro
        $creditosPendientes = VentaOrden::where('tipo_venta', 'credito')
            ->whereIn('estado', ['aprobada'])
            ->selectRaw('COUNT(*) as cantidad, SUM(total) as monto')
            ->first();

        // Aprobaciones pendientes
        $aprobacionesPendientes = VentaAprobacion::where('estado', 'pendiente')->count();

        // Ventas por producto (top 10) - este mes
        $ventasPorProducto = DB::connection('compras')
            ->table('ventas_orden_items as i')
            ->join('ventas_ordenes as o', 'o.id', '=', 'i.orden_id')
            ->whereBetween('o.created_at', [$inicioMes, $finMes])
            ->whereIn('o.estado', ['aprobada', 'completada'])
            ->selectRaw('i.nombre_producto, SUM(i.cantidad) as cantidad, SUM(i.total) as total')
            ->groupBy('i.nombre_producto')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        // Últimas 5 órdenes
        $ultimasOrdenes = VentaOrden::with('cliente')
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn($o) => [
                'id'         => $o->id,
                'cliente'    => $o->cliente?->nombres,
                'tipo_venta' => $o->tipo_venta,
                'total'      => $o->total,
                'estado'     => $o->estado,
                'created_at' => $o->created_at?->toDateTimeString(),
            ]);

        return response()->json([
            'ventas_mes' => [
                'total'       => (float) ($ventasMes->total_ventas ?? 0),
                'num_ordenes' => (int) ($ventasMes->num_ordenes ?? 0),
            ],
            'ventas_contado' => [
                'total'    => (float) ($ventasTipo['contado']->total ?? 0),
                'cantidad' => (int) ($ventasTipo['contado']->cantidad ?? 0),
            ],
            'ventas_credito' => [
                'total'    => (float) ($ventasTipo['credito']->total ?? 0),
                'cantidad' => (int) ($ventasTipo['credito']->cantidad ?? 0),
            ],
            'creditos_pendientes' => [
                'cantidad' => (int) ($creditosPendientes->cantidad ?? 0),
                'monto'    => (float) ($creditosPendientes->monto ?? 0),
            ],
            'aprobaciones_pendientes' => $aprobacionesPendientes,
            'ventas_por_producto'     => $ventasPorProducto,
            'ultimas_ordenes'         => $ultimasOrdenes,
        ]);
    }
}
