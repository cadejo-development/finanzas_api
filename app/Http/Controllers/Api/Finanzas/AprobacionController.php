<?php

namespace App\Http\Controllers\Api\Finanzas;

use App\Http\Controllers\Controller;
use App\Models\SolicitudPago;
use App\Models\System;
use App\Services\Finanzas\AprobacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AprobacionController extends Controller
{
    public function __construct(private readonly AprobacionService $aprobacionService) {}

    /**
     * Solicitudes que están pendientes de aprobación del usuario autenticado.
     * GET /api/pagos/mis-aprobaciones
     */
    public function misAprobaciones(): JsonResponse
    {
        /** @var \App\Models\User $actor */
        $actor  = auth()->user();
        $pagosSystem = System::where('codigo', 'pagos')->first();

        $solicitudes = $this->aprobacionService->pendientesParaActor($actor, $pagosSystem?->id ?? 0);

        return response()->json([
            'success' => true,
            'data'    => $solicitudes->values(),
        ]);
    }

    /**
     * Cadena completa de aprobación de una solicitud.
     * GET /api/pagos/solicitudes-pago/{id}/aprobaciones
     */
    public function cadena(int $id): JsonResponse
    {
        $solicitud = SolicitudPago::with(['estadoSolicitudPago', 'proveedor'])->findOrFail($id);
        $cadena    = $this->aprobacionService->cadenaOrdenada($solicitud);

        return response()->json([
            'success' => true,
            'data'    => [
                'solicitud' => [
                    'id'              => $solicitud->id,
                    'codigo'          => $solicitud->codigo,
                    'estado_codigo'   => $solicitud->estadoSolicitudPago?->codigo,
                    'estado_nombre'   => $solicitud->estadoSolicitudPago?->nombre,
                    'tipo_gasto'      => $solicitud->tipo_gasto,
                    'a_pagar'         => $solicitud->a_pagar,
                    'solicitante_nombre' => $solicitud->solicitante_nombre,
                ],
                'aprobaciones' => $cadena,
            ],
        ]);
    }

    /**
     * Aprobar la línea pendiente del usuario autenticado.
     * POST /api/pagos/solicitudes-pago/{id}/aprobar
     */
    public function aprobar(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'comentario' => 'nullable|string|max:500',
        ]);

        $solicitud = SolicitudPago::findOrFail($id);
        /** @var \App\Models\User $actor */
        $actor = auth()->user();

        $resultado = $this->aprobacionService->aprobar($solicitud, $actor, $request->input('comentario'));

        return response()->json([
            'success' => $resultado['ok'],
            'message' => $resultado['message'],
        ], $resultado['ok'] ? 200 : 422);
    }

    /**
     * Rechazar la línea pendiente del usuario autenticado.
     * POST /api/pagos/solicitudes-pago/{id}/rechazar
     */
    public function rechazar(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'comentario' => 'nullable|string|max:500',
        ]);

        $solicitud = SolicitudPago::findOrFail($id);
        /** @var \App\Models\User $actor */
        $actor = auth()->user();

        $resultado = $this->aprobacionService->rechazar($solicitud, $actor, $request->input('comentario'));

        return response()->json([
            'success' => $resultado['ok'],
            'message' => $resultado['message'],
        ], $resultado['ok'] ? 200 : 422);
    }
}
