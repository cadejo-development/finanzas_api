<?php

namespace App\Http\Controllers\Api\Ventas;

use App\Http\Controllers\Controller;
use App\Models\Ventas\VentaAprobacion;
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
