<?php

namespace App\Http\Controllers\Api\Ventas;

use App\Http\Controllers\Controller;
use App\Models\Ventas\VentaOrden;
use App\Models\Ventas\VentaPago;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PagosController extends Controller
{
    public function index(int $ordenId)
    {
        $orden   = VentaOrden::findOrFail($ordenId);
        $pagos   = VentaPago::where('orden_id', $orden->id)->orderBy('fecha')->get();
        $abonado = $pagos->sum('monto');

        return response()->json([
            'data'        => $pagos,
            'total_orden' => $orden->total,
            'abonado'     => $abonado,
            'saldo'       => round($orden->total - $abonado, 2),
        ]);
    }

    public function store(Request $request, int $ordenId)
    {
        $orden = VentaOrden::findOrFail($ordenId);

        $data = $request->validate([
            'fecha'          => 'required|date',
            'forma_pago'     => 'required|in:efectivo,transferencia,cheque,tarjeta,otro',
            'monto'          => 'required|numeric|min:0.01',
            'comprobante'    => 'nullable|string|max:100',
            'registrado_por' => 'nullable|string|max:100',
            'notas'          => 'nullable|string|max:500',
            'archivo'        => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:20480',
        ]);

        $comprobanteRuta   = null;
        $comprobanteNombre = null;

        if ($request->hasFile('archivo')) {
            $file              = $request->file('archivo');
            $comprobanteNombre = $file->getClientOriginalName();
            $comprobanteRuta   = $file->store("ventas/ordenes/{$orden->id}/comprobantes", 's3');
        }

        $pago = VentaPago::create([
            'orden_id'           => $orden->id,
            'fecha'              => $data['fecha'],
            'forma_pago'         => $data['forma_pago'],
            'monto'              => $data['monto'],
            'comprobante'        => $data['comprobante'] ?? null,
            'comprobante_ruta'   => $comprobanteRuta,
            'comprobante_nombre' => $comprobanteNombre,
            'registrado_por'     => $data['registrado_por'] ?? null,
            'notas'              => $data['notas'] ?? null,
        ]);

        // Si el saldo queda en 0, marcar la orden como completada
        $abonado = VentaPago::where('orden_id', $orden->id)->sum('monto');
        if ($abonado >= $orden->total && in_array($orden->estado, ['aprobada', 'despachada'])) {
            $orden->update(['estado' => 'completada']);
        }

        return response()->json(['success' => true, 'data' => $pago], 201);
    }

    public function downloadComprobante(int $ordenId, int $pagoId)
    {
        $pago = VentaPago::where('orden_id', $ordenId)->findOrFail($pagoId);

        if (!$pago->comprobante_ruta) {
            return response()->json(['message' => 'Sin comprobante adjunto'], 404);
        }

        $url = Storage::disk('s3')->temporaryUrl($pago->comprobante_ruta, now()->addMinutes(15));

        return response()->json(['success' => true, 'url' => $url]);
    }

    public function destroy(int $ordenId, int $pagoId)
    {
        $pago = VentaPago::where('orden_id', $ordenId)->findOrFail($pagoId);

        if ($pago->comprobante_ruta) {
            Storage::disk('s3')->delete($pago->comprobante_ruta);
        }

        $pago->delete();

        return response()->json(['success' => true]);
    }
}
