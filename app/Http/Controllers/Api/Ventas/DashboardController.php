<?php

namespace App\Http\Controllers\Api\Ventas;

use App\Http\Controllers\Controller;
use App\Models\Ventas\VentaOrden;
use App\Models\Ventas\VentaAprobacion;
use App\Models\Ventas\VentaCliente;
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

        // Top 5 clientes por ventas este mes
        $topClientes = DB::connection('compras')
            ->table('ventas_ordenes as o')
            ->join('ventas_clientes as c', 'c.id', '=', 'o.cliente_id')
            ->whereBetween('o.created_at', [$inicioMes, $finMes])
            ->whereIn('o.estado', ['aprobada', 'completada'])
            ->selectRaw('c.id, c.nombres, c.nom_comercial, SUM(o.total) as total, COUNT(o.id) as num_ordenes')
            ->groupBy('c.id', 'c.nombres', 'c.nom_comercial')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        // Clientes nuevos este mes
        $clientesNuevos = VentaCliente::whereBetween('created_at', [$inicioMes, $finMes])->count();

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

        // ── Aging de créditos vencidos ────────────────────────────────────────
        // Fecha de vencimiento = created_at + plazo_solicitado días
        $hoy = now()->toDateString();

        $creditosVivos = VentaOrden::where('tipo_venta', 'credito')
            ->whereIn('estado', ['aprobada', 'despachada'])
            ->get(['id', 'total', 'created_at', 'plazo_solicitado']);

        $aging = ['corriente' => 0, 'vencido_30' => 0, 'vencido_60' => 0, 'vencido_mas' => 0];
        $today = now()->startOfDay();
        foreach ($creditosVivos as $o) {
            $vence = \Carbon\Carbon::parse($o->created_at)->addDays((int)($o->plazo_solicitado ?? 0))->startOfDay();
            $dv    = $today->diffInDays($vence, false) * -1; // negativo = corriente, positivo = vencido
            if ($dv <= 0)       $aging['corriente']   += $o->total;
            elseif ($dv <= 30)  $aging['vencido_30']  += $o->total;
            elseif ($dv <= 60)  $aging['vencido_60']  += $o->total;
            else                $aging['vencido_mas']  += $o->total;
        }

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
            'creditos_aging' => [
                'corriente'   => round($aging['corriente'], 2),
                'vencido_30'  => round($aging['vencido_30'], 2),
                'vencido_60'  => round($aging['vencido_60'], 2),
                'vencido_mas' => round($aging['vencido_mas'], 2),
            ],
            'ventas_por_producto'     => $ventasPorProducto,
            'ultimas_ordenes'         => $ultimasOrdenes,
            'top_clientes'            => $topClientes,
            'clientes_nuevos'         => $clientesNuevos,
        ]);
    }
}
