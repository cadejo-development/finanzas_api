<?php

namespace App\Http\Controllers\Api\Ventas;

use App\Http\Controllers\Controller;
use App\Models\Ventas\VentaOrden;
use App\Models\Ventas\VentaPago;
use Illuminate\Http\Request;

class PagosController extends Controller
{
    public function index(int $ordenId)
    {
        $orden  = VentaOrden::findOrFail($ordenId);
        $pagos  = VentaPago::where('orden_id', $orden->id)->orderBy('fecha')->get();
        $abonado = $pagos->sum('monto');

        return response()->json([
            'data'          => $pagos,
            'total_orden'   => $orden->total,
            'abonado'       => $abonado,
            'saldo'         => round($orden->total - $abonado, 2),
        ]);
    }

    public function store(Request $request, int $ordenId)
    {
        $orden = VentaOrden::findOrFail($ordenId);

        $data = $request->validate([
            'fecha'         => 'required|date',
            'forma_pago'    => 'required|in:efectivo,transferencia,cheque,tarjeta,otro',
            'monto'         => 'required|numeric|min:0.01',
            'comprobante'   => 'nullable|string|max:100',
            'registrado_por'=> 'nullable|string|max:100',
            'notas'         => 'nullable|string|max:500',
        ]);

        $pago = VentaPago::create([
            'orden_id' => $orden->id,
            ...$data,
        ]);

        // Si el saldo queda en 0, marcar la orden como completada
        $abonado = VentaPago::where('orden_id', $orden->id)->sum('monto');
        if ($abonado >= $orden->total && in_array($orden->estado, ['aprobada', 'despachada'])) {
            $orden->update(['estado' => 'completada']);
        }

        return response()->json(['success' => true, 'data' => $pago], 201);
    }

    public function destroy(int $ordenId, int $pagoId)
    {
        $pago = VentaPago::where('orden_id', $ordenId)->findOrFail($pagoId);
        $pago->delete();

        return response()->json(['success' => true]);
    }
}
