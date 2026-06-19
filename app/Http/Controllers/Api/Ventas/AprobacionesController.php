<?php

namespace App\Http\Controllers\Api\Ventas;

use App\Http\Controllers\Controller;
use App\Models\Ventas\VentaAprobacion;
use App\Models\Ventas\VentaCliente;
use App\Models\Ventas\VentaOrden;
use Illuminate\Http\Request;

class AprobacionesController extends Controller
{
    public function index(Request $request)
    {
        $q = VentaAprobacion::with(['orden.cliente', 'cliente'])->latest();

        if ($request->filled('estado')) {
            $q->where('estado', $request->estado);
        }

        return response()->json($q->get()->map(fn($a) => $this->format($a)));
    }

    public function resolver(Request $request, $id)
    {
        $aprobacion = VentaAprobacion::findOrFail($id);

        $data = $request->validate([
            'estado'          => 'required|in:aprobado,rechazado',
            'resuelto_por'    => 'required|string',
            'nota_resolucion' => 'nullable|string',
        ]);

        $aprobacion->update([
            'estado'          => $data['estado'],
            'resuelto_por'    => $data['resuelto_por'],
            'nota_resolucion' => $data['nota_resolucion'] ?? null,
            'resuelto_at'     => now(),
        ]);

        // Actualizar estado de la orden asociada
        if ($aprobacion->orden_id) {
            $nuevoEstado = $data['estado'] === 'aprobado' ? 'aprobada' : 'rechazada';
            VentaOrden::where('id', $aprobacion->orden_id)->update([
                'estado'       => $nuevoEstado,
                'aprobado_por' => $data['resuelto_por'],
                'aprobado_at'  => now(),
            ]);
        }

        // Aplicar cambios de datos de cliente si fue aprobado
        if ($aprobacion->tipo === 'cambio_cliente' && $aprobacion->cliente_id && $data['estado'] === 'aprobado') {
            $detalle = json_decode($aprobacion->detalle, true);
            if (isset($detalle['datos'])) {
                $allowed  = ['nombres','nom_comercial','nit','registro_iva','email','telefono','direccion','exento','plazo_credito','limite_credito','catalogo_precio_id'];
                $toUpdate = array_intersect_key($detalle['datos'], array_flip($allowed));
                if (!empty($toUpdate)) {
                    VentaCliente::where('id', $aprobacion->cliente_id)->update($toUpdate);
                }
            }
        }

        return response()->json($this->format($aprobacion->fresh(['orden.cliente', 'cliente'])));
    }

    private function format(VentaAprobacion $a): array
    {
        return [
            'id'               => $a->id,
            'tipo'             => $a->tipo,
            'detalle'          => $a->detalle,
            'estado'           => $a->estado,
            'solicitado_por'   => $a->solicitado_por,
            'resuelto_por'     => $a->resuelto_por,
            'nota_resolucion'  => $a->nota_resolucion,
            'resuelto_at'      => $a->resuelto_at?->toDateTimeString(),
            'created_at'       => $a->created_at?->toDateTimeString(),
            'orden'            => $a->orden ? [
                'id'     => $a->orden->id,
                'total'  => $a->orden->total,
                'estado' => $a->orden->estado,
                'cliente'=> $a->orden->cliente?->nombres,
            ] : null,
            'cliente'          => $a->cliente ? [
                'id'      => $a->cliente->id,
                'nombres' => $a->cliente->nombres,
            ] : null,
        ];
    }
}
